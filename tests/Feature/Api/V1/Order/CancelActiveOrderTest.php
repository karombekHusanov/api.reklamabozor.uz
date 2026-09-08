<?php

namespace Tests\Feature\Api\V1\Order;

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
use Tests\TestCase;

/**
 * Deals activate on contract acceptance, so the client keeps a cancel path:
 * unlimited while unpaid, and a cooling-off window once paid.
 */
class CancelActiveOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.multicard.enabled' => true,
            'services.multicard.base_url' => 'https://dev-mesh.multicard.uz',
            'services.multicard.application_id' => 'rhmt_test',
            'services.multicard.secret' => 'test_secret',
            'services.multicard.store_id' => '6',
            'orders.paid_cancel_window_hours' => 24,
        ]);
    }

    /**
     * @return array{0: User, 1: Order, 2: Offer}
     */
    private function activeDeal(OrderPaymentState $state, int $priceSom = 2_000_000): array
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create([
            'payment_state' => $state,
            'payment_due_at' => $state === OrderPaymentState::Unpaid ? now()->addDays(3) : null,
            'paid_at' => $state === OrderPaymentState::Paid ? now() : null,
        ]);
        $profile = AgentProfile::factory()->create();
        $offer = Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'price' => $priceSom,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        return [$client, $order->fresh(), $offer];
    }

    public function test_client_cancels_an_unpaid_active_order(): void
    {
        Http::fake();
        [$client, $order] = $this->activeDeal(OrderPaymentState::Unpaid);

        // An open Multicard invoice the client never paid.
        $invoice = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'method' => PaymentMethod::Multicard,
            'status' => PaymentStatus::Draft,
            'gateway_uuid' => 'gw-open',
        ]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        // The dangling invoice is retired at the gateway and locally.
        $this->assertSame(PaymentStatus::Error, $invoice->fresh()->status);
    }

    public function test_client_cancels_a_paid_order_inside_the_window_and_is_refunded(): void
    {
        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/gw-paid' => Http::response(['success' => true, 'data' => ['status' => 'revert']]),
        ]);

        [$client, $order] = $this->activeDeal(OrderPaymentState::Paid);
        $order->update(['paid_at' => now()->subHours(2)]);

        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'method' => PaymentMethod::Multicard,
            'status' => PaymentStatus::Success,
            'gateway_uuid' => 'gw-paid',
            'amount' => 200_000_000,
            'paid_at' => now()->subHours(2),
        ]);

        // A planned (not yet released) advance must be voided by the refund.
        Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $order->acceptedOffer->agent_id,
            'tranche' => PayoutTranche::Advance,
            'status' => PayoutStatus::Pending,
            'amount' => 74_400_000,
        ]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertSame(OrderPaymentState::Refunded, $fresh->payment_state);
        $this->assertSame(PaymentStatus::Revert, $payment->fresh()->status);
        $this->assertSame(PayoutStatus::Cancelled, $order->payouts()->first()->status);
    }

    public function test_client_cannot_cancel_a_paid_order_after_the_window(): void
    {
        Http::fake();
        [$client, $order] = $this->activeDeal(OrderPaymentState::Paid);
        $order->update(['paid_at' => now()->subHours(30)]);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'gateway_uuid' => 'gw-late',
            'paid_at' => now()->subHours(30),
        ]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertUnprocessable();

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_client_cannot_cancel_once_a_payout_was_released(): void
    {
        Http::fake();
        [$client, $order] = $this->activeDeal(OrderPaymentState::Paid);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'gateway_uuid' => 'gw-out',
            'paid_at' => now(),
        ]);
        Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $order->acceptedOffer->agent_id,
            'tranche' => PayoutTranche::Advance,
            'status' => PayoutStatus::Paid,
            'amount' => 10_000,
        ]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertUnprocessable();

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_cash_paid_order_cancel_flags_a_manual_refund(): void
    {
        Http::fake();
        [$client, $order] = $this->activeDeal(OrderPaymentState::Paid);

        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'method' => PaymentMethod::Cash,
            'gateway' => 'offline',
            'status' => PaymentStatus::Success,
            'gateway_uuid' => null,
            'paid_at' => now(),
        ]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Revert, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
    }

    public function test_delivered_work_can_no_longer_be_cancelled(): void
    {
        Http::fake();
        [$client, $order] = $this->activeDeal(OrderPaymentState::Unpaid);
        $order->update(['status' => OrderStatus::WorkSubmitted]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertUnprocessable();
    }

    public function test_order_detail_exposes_the_cancel_window(): void
    {
        Http::fake();
        [$client, $order] = $this->activeDeal(OrderPaymentState::Paid);
        $order->update(['paid_at' => now()->subHour()]);

        $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonStructure(['data' => ['cancel_deadline_at']]);

        $order->update(['paid_at' => now()->subHours(48)]);

        $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', false);
    }
}
