<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Faza 1: the client may only ask for changes during the first slice of the
 * agreed delivery time; the agent is never time-limited. Money is derived from
 * the ledger, so payouts wait for the full amount.
 */
class AmendmentWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake((string) config('files.disk'));
        config(['orders.amendment_client_window_divisor' => 3]);
    }

    /**
     * @return array{0: User, 1: User, 2: Order}
     */
    private function activeDeal(int $deadlineDays = 12, int $priceSom = 12_000_000): array
    {
        $category = Category::factory()->create();
        $client = User::factory()->create();
        $agent = User::factory()->create(['telegram_id' => 720100300]);
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $profile->categories()->attach($category);

        $order = Order::factory()->for($category)->for($client, 'client')
            ->status(OrderStatus::InProgress)
            ->create(['activated_at' => now()]);

        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => $priceSom,
            'deadline_days' => $deadlineDays,
        ]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Bilbord 3x6',
            'unit' => 'dona',
            'quantity' => 1,
            'unit_price' => $priceSom,
            'sort_order' => 0,
        ]);

        return [$client, $agent, $order->fresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(int $priceSom, int $deadlineDays = 12): array
    {
        return [
            'items' => [['name' => 'Bilbord 3x6', 'unit' => 'dona', 'quantity' => 1, 'unit_price' => $priceSom]],
            'deadline_days' => $deadlineDays,
            'reason' => 'Hajm o\'zgardi',
            'accept_contract' => true,
        ];
    }

    public function test_client_may_propose_inside_the_first_third(): void
    {
        // 12-day deal → 4-day window; we are on day 3.
        [$client, , $order] = $this->activeDeal();
        $order->update(['activated_at' => now()->subDays(3)]);

        $this->actingAs($client)
            ->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(13_000_000))
            ->assertCreated();
    }

    public function test_client_cannot_propose_after_the_window(): void
    {
        [$client, , $order] = $this->activeDeal();
        $order->update(['activated_at' => now()->subDays(5)]); // window ended on day 4

        $this->actingAs($client)
            ->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(13_000_000))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amendment');

        $this->assertSame(0, $order->amendments()->count());
    }

    public function test_agent_may_propose_at_any_time(): void
    {
        [, $agent, $order] = $this->activeDeal();
        $order->update(['activated_at' => now()->subDays(11)]); // long past the client's window

        $this->actingAs($agent)
            ->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(14_000_000))
            ->assertCreated();
    }

    public function test_window_falls_back_when_the_offer_has_no_deadline(): void
    {
        config(['orders.amendment_client_window_fallback_days' => 3]);
        [$client, , $order] = $this->activeDeal();
        $order->acceptedOffer->update(['deadline_days' => null]);
        $order->update(['activated_at' => now()->subDays(4)]);

        $this->actingAs($client)
            ->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(13_000_000, 12))
            ->assertUnprocessable();
    }

    public function test_order_detail_exposes_the_amendment_window(): void
    {
        [$client, , $order] = $this->activeDeal();
        $order->update(['activated_at' => now()->subDay()]);

        $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.amendment_window.can_propose', true)
            ->assertJsonPath('data.amendment_window.reason', 'ok')
            ->assertJsonStructure(['data' => ['amendment_window' => ['ends_at'], 'outstanding_som', 'activated_at']]);

        $order->update(['activated_at' => now()->subDays(9)]);

        $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.amendment_window.can_propose', false)
            ->assertJsonPath('data.amendment_window.reason', 'window_closed');
    }

    public function test_activation_stamps_activated_at(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['price' => 900_000]);

        $this->actingAs($client)
            ->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true])
            ->assertOk();

        $this->assertNotNull($order->fresh()->activated_at);
    }

    public function test_ledger_tracks_partial_payments(): void
    {
        [, , $order] = $this->activeDeal(12, 10_000_000);
        $order->update(['payment_state' => OrderPaymentState::Unpaid]);

        $this->assertSame(1_000_000_000, $order->dueTiyin());
        $this->assertSame(0, $order->paidTiyin());

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'amount' => 400_000_000, // part payment
            'paid_at' => now(),
        ]);

        $order->refresh()->recalculatePaymentState();
        $this->assertSame(600_000_000, $order->fresh()->outstandingTiyin());
        $this->assertSame(OrderPaymentState::Unpaid, $order->fresh()->payment_state);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'amount' => 600_000_000,
            'paid_at' => now(),
        ]);

        $order->refresh()->recalculatePaymentState();
        $this->assertSame(0, $order->fresh()->outstandingTiyin());
        $this->assertSame(OrderPaymentState::Paid, $order->fresh()->payment_state);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_offline_order_is_never_dragged_into_the_ledger(): void
    {
        // Gateway off → nothing is collected; the ledger must leave it alone.
        [, , $order] = $this->activeDeal();
        $this->assertSame(OrderPaymentState::NotRequired, $order->payment_state);

        $order->recalculatePaymentState();

        $this->assertSame(OrderPaymentState::NotRequired, $order->fresh()->payment_state);
    }
}
