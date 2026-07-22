<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payout\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function configureSplit(): void
    {
        config([
            'services.multicard.enabled' => true,
            'services.multicard.commission_percent' => 7,
            'services.multicard.payout_advance_percent' => 40,
        ]);
    }

    /** An order with an accepted offer (from an agency with a profile) at the given price (som). */
    private function orderWithAcceptedOffer(int $priceSom): Order
    {
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->status(OrderStatus::InProgress)->create();
        Offer::factory()->for($order)->accepted()->create([
            'price' => $priceSom,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        return $order->fresh();
    }

    public function test_advance_and_final_split_the_net_after_commission(): void
    {
        $this->configureSplit();
        $payouts = app(PayoutService::class);

        // 10 mln som = 1,000,000,000 tiyin. Commission 7% = 70,000,000.
        // Net = 930,000,000. Advance 40% = 372,000,000. Final = 558,000,000.
        $order = $this->orderWithAcceptedOffer(10_000_000);

        $advance = $payouts->planAdvance($order);
        $this->assertNotNull($advance);
        $this->assertSame(PayoutTranche::Advance, $advance->tranche);
        $this->assertSame(372_000_000, $advance->amount);
        $this->assertSame(PayoutStatus::Pending, $advance->status);

        $final = $payouts->planFinal($order);
        $this->assertNotNull($final);
        $this->assertSame(PayoutTranche::Final, $final->tranche);
        $this->assertSame(558_000_000, $final->amount);

        // Advance + final = the full net owed to the agent.
        $this->assertSame(930_000_000, (int) $order->payouts()->sum('amount'));
    }

    public function test_final_honours_a_manager_overridden_advance(): void
    {
        $this->configureSplit();
        $payouts = app(PayoutService::class);
        $order = $this->orderWithAcceptedOffer(10_000_000);

        $advance = $payouts->planAdvance($order);
        // Manager releases a smaller advance than the default 40%.
        $payouts->release($advance, User::factory()->create(), ['amount' => 300_000_000]);

        $final = $payouts->planFinal($order);
        // Final = net (930m) - actual advance (300m) = 630m.
        $this->assertSame(630_000_000, $final->amount);
    }

    public function test_no_payout_when_gateway_disabled(): void
    {
        config(['services.multicard.enabled' => false]);
        $order = $this->orderWithAcceptedOffer(5_000_000);

        $this->assertNull(app(PayoutService::class)->planAdvance($order));
        $this->assertSame(0, $order->payouts()->count());
    }

    public function test_plan_advance_is_idempotent(): void
    {
        $this->configureSplit();
        $payouts = app(PayoutService::class);
        $order = $this->orderWithAcceptedOffer(5_000_000);

        $payouts->planAdvance($order);
        $payouts->planAdvance($order);

        $this->assertSame(1, $order->payouts()->where('tranche', PayoutTranche::Advance)->count());
    }

    public function test_manager_can_release_a_pending_payout(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $token = $admin->createToken('t')->plainTextToken;

        $payout = Payout::factory()->create(['amount' => 372_000_000]);

        $this->patchJson("/api/v1/admin/payouts/{$payout->id}/release", [
            'amount' => 350_000_000,
            'reference' => 'BANK-REF-42',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', 350_000_000)
            ->assertJsonPath('data.reference', 'BANK-REF-42');

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertSame($admin->id, $payout->released_by);
        $this->assertNotNull($payout->paid_at);
    }

    public function test_releasing_a_non_pending_payout_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $token = $admin->createToken('t')->plainTextToken;
        $payout = Payout::factory()->paid()->create();

        $this->patchJson("/api/v1/admin/payouts/{$payout->id}/release", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_list_payouts(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/v1/admin/payouts', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }
}
