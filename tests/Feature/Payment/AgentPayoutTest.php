<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payout\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AgentPayoutTest extends TestCase
{
    use RefreshDatabase;

    private function configureSplit(): void
    {
        config([
            'payments.enabled' => true,
            'payments.commission_percent' => 7,
            'payments.advance_percent' => 40,
        ]);
    }

    /**
     * An active, paid order with an accepted offer (from an agency with a
     * profile) at the given price (som). Payouts only follow settled money.
     */
    private function orderWithAcceptedOffer(int $priceSom, bool $paid = true): Order
    {
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => $paid ? OrderPaymentState::Paid : OrderPaymentState::Unpaid,
            // Past the cooling-off window, so releases are not frozen here.
            'paid_at' => $paid ? now()->subHours(2) : null,
        ]);

        if ($paid) {
            // Payouts follow settled money, so the ledger needs a real payment.
            Payment::factory()->create([
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'purpose' => PaymentPurpose::Order,
                'status' => PaymentStatus::Success,
                'amount' => $priceSom * 100,
                'paid_at' => now()->subHours(2),
            ]);
        }
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
        $payouts->release($advance, User::factory()->create(), ['amount' => 300_000_000, 'reference' => 'PO-1']);

        $final = $payouts->planFinal($order);
        // Final = net (930m) - actual advance (300m) = 630m.
        $this->assertSame(630_000_000, $final->amount);
    }

    public function test_release_is_frozen_until_the_cancel_window_closes(): void
    {
        $this->configureSplit();
        config(['orders.paid_cancel_window_minutes' => 60]);
        $payouts = app(PayoutService::class);

        $order = $this->orderWithAcceptedOffer(5_000_000);
        // Money landed a moment ago — the client can still take it back.
        $order->update(['paid_at' => now()->subMinutes(10)]);
        $advance = $payouts->planAdvance($order->fresh());
        $this->assertNotNull($advance);

        $manager = User::factory()->create();

        try {
            $payouts->release($advance, $manager, ['reference' => 'PO-2']);
            $this->fail('Releasing inside the cancel window should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('payouts unlock in', $e->getMessage());
        }

        $this->assertSame(PayoutStatus::Pending, $advance->fresh()->status);

        // Window closed → the manager may release it.
        $order->update(['paid_at' => now()->subMinutes(61)]);

        $released = $payouts->release($advance->fresh(), $manager, ['reference' => 'PO-2']);
        $this->assertSame(PayoutStatus::Paid, $released->status);
    }

    public function test_no_payout_when_payments_disabled(): void
    {
        config(['payments.enabled' => false]);
        // Payments off → the platform collects nothing (payment_state stays
        // not_required), so there is no money to pay out.
        $order = Order::factory()->status(OrderStatus::InProgress)->create();
        $profile = AgentProfile::factory()->create();
        Offer::factory()->for($order)->accepted()->create([
            'price' => 5_000_000,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        $this->assertNull(app(PayoutService::class)->planAdvance($order->fresh()));
        $this->assertSame(0, $order->payouts()->count());
    }

    public function test_no_payout_while_the_active_order_is_still_unpaid(): void
    {
        $this->configureSplit();
        // Deal activated on contract acceptance, money not in yet.
        $order = $this->orderWithAcceptedOffer(5_000_000, paid: false);

        $this->assertNull(app(PayoutService::class)->planAdvance($order));
        $this->assertNull(app(PayoutService::class)->planFinal($order));
        $this->assertSame(0, $order->payouts()->count());
    }

    public function test_final_payout_is_held_while_the_deal_still_owes(): void
    {
        $this->configureSplit();
        $payouts = app(PayoutService::class);
        $order = $this->orderWithAcceptedOffer(10_000_000);

        $payouts->planAdvance($order);

        // An applied amendment raises the deal amount — the extra is not in yet.
        $order->acceptedOffer->update(['price' => 12_000_000]);
        $order->refresh();

        $this->assertSame(200_000_000, $order->outstandingTiyin()); // 2 mln som
        $this->assertNull($payouts->planFinal($order));

        // Client settles the difference → the final tranche can be planned.
        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'amount' => 200_000_000,
            'paid_at' => now(),
        ]);

        $this->assertNotNull($payouts->planFinal($order->fresh()));
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

    public function test_duplicate_tranche_is_blocked_by_the_unique_index_and_swallowed(): void
    {
        $this->configureSplit();
        $payouts = app(PayoutService::class);
        $order = $this->orderWithAcceptedOffer(5_000_000);
        $advance = $payouts->planAdvance($order);

        // Simulate a racing request that passed the exists() check already.
        $method = new \ReflectionMethod($payouts, 'createPayout');
        $again = $method->invoke($payouts, $order, $order->acceptedOffer, PayoutTranche::Advance, 1);

        $this->assertNull($again);
        $this->assertSame(1, $order->payouts()->count());
        $this->assertNotNull($advance);
    }
}
