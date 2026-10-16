<?php

namespace Tests\Feature\Admin;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin status changes go through the domain transitions (audit 2026-10-16
 * §1.3): money, payouts, acts and the deal chat follow the status instead of
 * a bare column write.
 */
class AdminOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake((string) config('files.disk'));
        config([
            'payments.enabled' => true,
            'payments.commission_percent' => 7,
            'payments.advance_percent' => 40,
            'orders.paid_cancel_window_minutes' => 60,
        ]);
    }

    private function admin(): array
    {
        $admin = User::factory()->admin()->create();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    /**
     * @return array{0: Order, 1: AgentProfile, 2: Offer}
     */
    private function deal(OrderStatus $status, OrderPaymentState $state, int $priceSom = 1_000_000): array
    {
        $profile = AgentProfile::factory()->approved()->create();
        $order = Order::factory()->status($status)->create([
            'payment_state' => $state,
            'activated_at' => now()->subDay(),
            'paid_at' => $state === OrderPaymentState::Paid ? now()->subHours(2) : null,
            'work_submitted_at' => $status === OrderStatus::WorkSubmitted ? now() : null,
        ]);
        $offer = Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'price' => $priceSom,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        if ($state === OrderPaymentState::Paid) {
            Payment::factory()->create([
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'payer_id' => $order->client_id,
                'purpose' => PaymentPurpose::Order,
                'method' => PaymentMethod::BankTransfer,
                'status' => PaymentStatus::Success,
                'amount' => $priceSom * 100,
                'paid_at' => now()->subHours(2),
            ]);
        }

        return [$order->fresh(), $profile, $offer];
    }

    private function setStatus(Order $order, string $status)
    {
        return $this->patchJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status], $this->admin());
    }

    public function test_cancelling_a_paid_order_refunds_and_voids_the_payout(): void
    {
        [$order, $profile] = $this->deal(OrderStatus::InProgress, OrderPaymentState::Paid);
        $advance = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'tranche' => PayoutTranche::Advance,
            'amount' => 40_000_000,
        ]);

        $this->setStatus($order, 'cancelled')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_state', 'refunded');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertSame(OrderPaymentState::Refunded, $fresh->payment_state);
        $this->assertSame(PaymentStatus::Revert, Payment::firstOrFail()->status);
        $this->assertSame('admin', Payment::firstOrFail()->meta['refund_source'] ?? null);
        $this->assertSame(PayoutStatus::Cancelled, $advance->fresh()->status);

        // The voided payout can never be released afterwards.
        $this->patchJson("/api/v1/admin/payouts/{$advance->id}/release", ['reference' => 'PO-1'], $this->admin())
            ->assertUnprocessable();
        $this->assertSame(PayoutStatus::Cancelled, $advance->fresh()->status);
    }

    public function test_cancelling_a_paid_order_is_refused_once_a_payout_was_released(): void
    {
        [$order, $profile] = $this->deal(OrderStatus::InProgress, OrderPaymentState::Paid);
        Payout::factory()->paid()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        $this->setStatus($order, 'cancelled')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Success, Payment::firstOrFail()->status);
    }

    public function test_cancelling_an_unpaid_order_voids_invoices_and_pending_payouts(): void
    {
        [$order, $profile] = $this->deal(OrderStatus::InProgress, OrderPaymentState::Unpaid);
        $invoice = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Progress,
        ]);
        $payout = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        $this->setStatus($order, 'cancelled')->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(PaymentStatus::Error, $invoice->fresh()->status);
        $this->assertSame(PayoutStatus::Cancelled, $payout->fresh()->status);
    }

    public function test_completing_an_order_plans_the_final_payout_and_closes_the_books(): void
    {
        [$order] = $this->deal(OrderStatus::WorkSubmitted, OrderPaymentState::Paid, 10_000_000);

        $this->setStatus($order, 'completed')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertFalse($fresh->auto_completed);

        // Final tranche = net (93%) minus nothing already planned.
        $final = $fresh->payouts()->where('tranche', PayoutTranche::Final)->firstOrFail();
        $this->assertSame(930_000_000, $final->amount);
        $this->assertSame(PayoutStatus::Pending, $final->status);

        // Accounting acts are generated with the completion.
        $this->assertSame(2, $fresh->documents()->count());
    }

    public function test_sending_submitted_work_back_keeps_the_deal_running(): void
    {
        [$order] = $this->deal(OrderStatus::WorkSubmitted, OrderPaymentState::Paid);

        $this->setStatus($order, 'in_progress')->assertOk()->assertJsonPath('data.status', 'in_progress');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::InProgress, $fresh->status);
        $this->assertNull($fresh->work_submitted_at);
        $this->assertSame(0, $fresh->payouts()->count());
    }
}
