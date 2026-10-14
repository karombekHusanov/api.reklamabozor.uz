<?php

namespace Tests\Feature\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\TezkorBilling;
use App\Enums\WalletTransactionType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Pass\PassSettings;
use App\Services\Pass\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tezkor `pay_on_pick`: otkliks are free, the client picks one agency and that
 * agency pays the per-order fee to take the job (or the pick lapses).
 */
class TezkorPayOnPickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['passes.enforce' => true, 'passes.wallet_enabled' => true, 'passes.response_price_som' => 1000]);
        app(PassSettings::class)->update(['tezkor_billing' => 'pay_on_pick', 'pick_pay_minutes' => 30]);
    }

    private function auth(User $user): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->approved()->create()
            ->categories()->attach(Category::factory()->create());

        return $user;
    }

    /** A Tezkor request placed through the API, so it freezes the current billing. */
    private function tezkor(User $client): Order
    {
        $id = $this->postJson('/api/v1/orders', [
            'description' => 'Tezkor ish', 'route' => 'tezkor', 'lat' => 41.31, 'lng' => 69.28,
        ], $this->auth($client))->assertCreated()->json('data.id');

        return Order::query()->findOrFail($id);
    }

    private function otklik(User $agent, Order $order): Offer
    {
        $id = $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))
            ->assertCreated()->json('data.id');

        return Offer::query()->findOrFail($id);
    }

    private function pick(User $client, Order $order, Offer $offer)
    {
        return $this->postJson("/api/v1/orders/{$order->id}/close", ['offer_id' => $offer->id], $this->auth($client));
    }

    private function fund(User $agent, int $tiyin = 100000): void
    {
        app(WalletService::class)->credit($agent, WalletTransactionType::Topup, $tiyin, 'gp:test:'.$agent->id);
    }

    public function test_billing_is_frozen_on_the_order_at_creation(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $this->assertSame(TezkorBilling::PayOnPick, $order->tezkor_billing);

        app(PassSettings::class)->update(['tezkor_billing' => 'pay_to_respond']);

        $this->assertSame(TezkorBilling::PayOnPick, $order->fresh()->tezkorBilling());
        $this->assertSame(TezkorBilling::PayToRespond, $this->tezkor($client)->tezkor_billing);
    }

    public function test_otklik_is_free_and_billing_is_shown(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $agent = $this->agent();

        $this->getJson("/api/v1/orders/showcase/{$order->id}", $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.billing.mode', 'pay_on_pick')
            ->assertJsonPath('data.billing.otklik_fee_som', 0)
            ->assertJsonPath('data.billing.take_fee_som', 1000);

        // Zero balance — the otklik still goes through and costs nothing.
        $offer = $this->otklik($agent, $order);
        $this->assertNull($offer->fee_tiyin);
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    public function test_pick_reserves_a_window_and_blocks_a_second_pick(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $a = $this->agent();
        $b = $this->agent();
        $offerA = $this->otklik($a, $order);
        $offerB = $this->otklik($b, $order);

        $this->pick($client, $order, $offerA)
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::OffersSent->value)
            ->assertJsonPath('data.tezkor_billing', 'pay_on_pick')
            ->assertJsonPath('data.pick.offer_id', $offerA->id)
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.claim', null);

        // Same pick again is harmless; switching while the window runs is not.
        $this->pick($client, $order, $offerA)->assertOk();
        $this->pick($client, $order, $offerB)->assertStatus(422)->assertJsonValidationErrors('offer_id');

        $this->getJson("/api/v1/agent/offers/{$offerA->id}", $this->auth($a))
            ->assertOk()
            ->assertJsonPath('data.pick.fee_som', 1000)
            ->assertJsonPath('data.order.billing.take_fee_som', 1000);
        $this->getJson("/api/v1/agent/offers/{$offerB->id}", $this->auth($b))
            ->assertOk()
            ->assertJsonPath('data.pick', null);
    }

    public function test_picked_agent_pays_to_take_the_job(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $a = $this->agent();
        $b = $this->agent();
        $offerA = $this->otklik($a, $order);
        $offerB = $this->otklik($b, $order);
        $this->pick($client, $order, $offerA)->assertOk();

        // Only the picked agent can take it.
        $this->fund($b);
        $this->postJson("/api/v1/agent/offers/{$offerB->id}/take", [], $this->auth($b))->assertStatus(422);

        // Short balance → 402, the pick stays for a retry.
        $this->postJson("/api/v1/agent/offers/{$offerA->id}/take", [], $this->auth($a))
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_balance')
            ->assertJsonPath('data.price_som', 1000);
        $this->assertSame($offerA->id, $order->fresh()->picked_offer_id);

        $this->fund($a, 150000);
        $this->postJson("/api/v1/agent/offers/{$offerA->id}/take", [], $this->auth($a))
            ->assertOk()
            ->assertJsonPath('data.status', OfferStatus::Accepted->value);

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame($a->id, $order->claimed_agent_id);
        $this->assertSame(OfferStatus::Rejected, $offerB->fresh()->status);
        $this->assertSame(100000, $offerA->fresh()->fee_tiyin);
        $this->assertSame(50000, app(WalletService::class)->balanceTiyin($a));
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::TakeFee)->count());

        // The agency that took the job is the work partner (contacts / exact location reveal).
        $this->assertSame($a->id, $order->workPartnerId());

        // A replay cannot charge twice.
        $this->postJson("/api/v1/agent/offers/{$offerA->id}/take", [], $this->auth($a))->assertStatus(422);
        $this->assertSame(50000, app(WalletService::class)->balanceTiyin($a));

        $this->getJson('/api/v1/admin/passes/summary', $this->auth(User::factory()->admin()->create()))
            ->assertOk()
            ->assertJsonPath('data.takes.count', 1)
            ->assertJsonPath('data.takes.sum_som', 1000)
            ->assertJsonPath('data.revenue_som', 1000)
            ->assertJsonPath('data.per_day.0.takes_count', 1)
            ->assertJsonPath('data.per_day.0.takes_sum_som', 1000);
    }

    public function test_declining_frees_the_client_to_pick_again(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $a = $this->agent();
        $b = $this->agent();
        $offerA = $this->otklik($a, $order);
        $offerB = $this->otklik($b, $order);
        $this->pick($client, $order, $offerA)->assertOk();

        $this->postJson("/api/v1/agent/offers/{$offerA->id}/decline-pick", [], $this->auth($a))
            ->assertOk()
            ->assertJsonPath('data.status', OfferStatus::Withdrawn->value);

        $this->assertNull($order->fresh()->picked_offer_id);
        $this->pick($client, $order, $offerB)->assertOk()->assertJsonPath('data.pick.offer_id', $offerB->id);
    }

    public function test_withdrawing_the_picked_otklik_clears_the_pick(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $a = $this->agent();
        $offerA = $this->otklik($a, $order);
        $this->pick($client, $order, $offerA)->assertOk();

        $this->postJson("/api/v1/agent/offers/{$offerA->id}/withdraw", [], $this->auth($a))->assertOk();

        $this->assertNull($order->fresh()->picked_offer_id);
    }

    public function test_unpaid_pick_lapses_after_the_window(): void
    {
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $a = $this->agent();
        $b = $this->agent();
        $offerA = $this->otklik($a, $order);
        $offerB = $this->otklik($b, $order);
        $this->pick($client, $order, $offerA)->assertOk();
        $this->fund($a);

        $this->travel(31)->minutes();

        // Expired even before the sweeper runs: no take, the client may re-pick.
        $this->postJson("/api/v1/agent/offers/{$offerA->id}/take", [], $this->auth($a))->assertStatus(422);
        $this->getJson("/api/v1/orders/{$order->id}", $this->auth($client))
            ->assertOk()->assertJsonPath('data.pick', null)->assertJsonPath('data.can_close', true);

        $this->artisan('tezkor:expire-picks')->assertSuccessful();
        $this->assertNull($order->fresh()->picked_offer_id);
        $this->assertSame(OfferStatus::Pending, $offerA->fresh()->status);
        $this->assertSame(100000, app(WalletService::class)->balanceTiyin($a));

        $this->pick($client, $order, $offerB)->assertOk();
    }

    public function test_pay_to_respond_request_cannot_be_taken(): void
    {
        app(PassSettings::class)->update(['tezkor_billing' => 'pay_to_respond']);
        $client = User::factory()->create();
        $order = $this->tezkor($client);
        $a = $this->agent();
        $this->fund($a);
        $offer = $this->otklik($a, $order);
        $this->assertSame(100000, $offer->fee_tiyin);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/take", [], $this->auth($a))->assertStatus(422);

        // "Kelishildi" closes it at once, no second fee.
        $this->pick($client, $order, $offer)->assertOk()->assertJsonPath('data.status', OrderStatus::Completed->value);
        $this->assertSame(0, app(WalletService::class)->balanceTiyin($a));
    }
}
