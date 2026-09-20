<?php

namespace Tests\Feature\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_offer_activates_the_deal_and_leaves_the_payment_outstanding(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $chosen = Offer::factory()->for($order)->create(['price' => 3_000_000]);

        $this->postJson("/api/v1/offers/{$chosen->id}/accept", ['accept_contract' => true], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.offer.status', 'accepted')
            // The client picks how to pay afterwards — no checkout is forced.
            ->assertJsonPath('data.payment', null);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::InProgress, $fresh->status);
        $this->assertSame(OrderPaymentState::Unpaid, $fresh->payment_state);
        $this->assertNotNull($fresh->payment_due_at);
        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('chats', ['order_id' => $order->id]);
    }

    public function test_client_requests_a_cash_invoice_and_admin_confirms_it(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $response = $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'cash',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.status', 'progress');

        $this->assertNotNull($response->json('data.invoice_url')); // hisob-faktura PDF

        $payment = Payment::firstOrFail();
        $this->assertSame(2_000_000 * 100, $payment->amount);
        $this->assertSame(OrderPaymentState::Unpaid, $order->fresh()->payment_state);

        // Money arrives at the cash desk → a manager settles it.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/payments/{$payment->id}/confirm", [
            'reference' => 'KASSA-42',
            'note' => 'Naqd, ofisda qabul qilindi',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.reference', 'KASSA-42');

        $fresh = $order->fresh();
        $this->assertSame(OrderPaymentState::Paid, $fresh->payment_state);
        $this->assertNotNull($fresh->paid_at);
        $this->assertNull($fresh->payment_due_at);
        // Money is in — the agent's advance payout is planned.
        $this->assertSame(1, $fresh->payouts()->count());
        // Manual admin confirmation is tagged, distinct from a Kapitalbank
        // auto-reconciliation match.
        $this->assertSame('admin', $payment->fresh()->matched_via);
    }

    public function test_offline_invoice_defaults_to_100_percent_of_outstanding(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.percent', 100)
            ->assertJsonPath('data.matched_via', null);

        $payment = Payment::firstOrFail();
        $this->assertSame(2_000_000 * 100, $payment->amount);
        $this->assertSame(100, $payment->percent);
    }

    public function test_client_can_request_half_of_the_outstanding_amount(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
            'percent' => 50,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.percent', 50);

        $payment = Payment::firstOrFail();
        $this->assertSame(1_000_000 * 100, $payment->amount); // half of 2,000,000 som
        $this->assertSame(50, $payment->percent);
    }

    public function test_second_offline_invoice_after_a_half_payment_bills_only_the_remainder(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
            'percent' => 50,
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $first = Payment::firstOrFail();
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('a')->plainTextToken;

        // The sanctum guard caches the resolved user for the request it was
        // first built against; switching actor (client → admin → client) in
        // one test needs a forgetGuards() between calls or the later request
        // keeps authenticating as whoever resolved first.
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/admin/payments/{$first->id}/confirm", [
            'reference' => 'BT-1',
        ], ['Authorization' => 'Bearer '.$adminToken])->assertOk();

        $this->assertSame(1_000_000 * 100, $order->fresh()->outstandingTiyin());

        $this->app['auth']->forgetGuards();

        // Client now asks to pay "the rest" — no tranche bookkeeping needed,
        // outstandingTiyin() has already shrunk.
        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
            'percent' => 100,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.percent', 100);

        $second = Payment::where('id', '!=', $first->id)->firstOrFail();
        $this->assertSame(1_000_000 * 100, $second->amount); // remaining half only

        // The stale 50%-flavoured intent from before was superseded, not reused.
        $this->assertSame(PaymentStatus::Success, $first->fresh()->status);
    }

    public function test_changing_percent_voids_the_stale_pending_offline_intent(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
            'percent' => 100,
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $first = Payment::firstOrFail();

        // Client changes their mind before anyone confirms the first invoice.
        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
            'percent' => 50,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.percent', 50);

        $this->assertSame(PaymentStatus::Error, $first->fresh()->status);
        $this->assertSame(2, Payment::count());
    }

    public function test_offline_invoice_rejects_a_different_method_while_one_is_pending(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'cash',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $this->assertSame(1, Payment::count());
    }

    public function test_offline_invoice_rejects_an_unsupported_percent(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
            'percent' => 30,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('percent');

        $this->assertSame(0, Payment::count());
    }

    public function test_offline_payment_can_be_rejected_by_admin(): void
    {
        Storage::fake((string) config('files.disk'));

        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $payment = Payment::firstOrFail();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/payments/{$payment->id}/reject", [
            'note' => 'Pul kelmadi',
        ])->assertOk()->assertJsonPath('data.status', 'error');

        $this->assertSame(OrderPaymentState::Unpaid, $order->fresh()->payment_state);
        $this->assertSame(0, $order->fresh()->payouts()->count());
    }

    public function test_paid_order_cannot_be_paid_again(): void
    {
        [$client, $token, $order] = $this->activeUnpaidOrder(1_000_000);
        $order->update(['payment_state' => OrderPaymentState::Paid, 'paid_at' => now()]);

        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", [
            'method' => 'bank_transfer',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_overdue_payment_reminder_runs_once_a_day(): void
    {
        [, , $order] = $this->activeUnpaidOrder(1_200_000);
        $order->update(['payment_due_at' => now()->subDay()]);

        $this->artisan('orders:remind-unpaid')->assertSuccessful();

        $reminded = $order->fresh()->payment_reminded_at;
        $this->assertNotNull($reminded);
        // Order is NOT cancelled — the agent may already be working.
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);

        // Same day → no second nudge.
        $this->artisan('orders:remind-unpaid')->assertSuccessful();
        $this->assertTrue($reminded->equalTo($order->fresh()->payment_reminded_at));
    }

    public function test_payments_disabled_activates_without_a_payment_obligation(): void
    {
        config(['payments.enabled' => false]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['price' => 900_000]);

        $this->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::InProgress, $fresh->status);
        $this->assertSame(OrderPaymentState::NotRequired, $fresh->payment_state);
        $this->assertNull($fresh->payment_due_at);
    }

    public function test_admin_refund_cancels_the_order(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $client = User::factory()->create();
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'paid_at' => now(),
        ]);
        Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'amount' => 80_000,
            'status' => PayoutStatus::Pending,
        ]);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'revert');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('admin', $payment->fresh()->meta['refund_source'] ?? null);
        $this->assertSame(PayoutStatus::Cancelled, $order->fresh()->payouts()->first()->status);
    }

    public function test_admin_refund_blocked_when_payout_already_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $order = Order::factory()->status(OrderStatus::InProgress)->create();
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Success,
            'paid_at' => now(),
        ]);
        Payout::factory()->create([
            'order_id' => $order->id,
            'amount' => 80_000,
            'status' => PayoutStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422);

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_client_cannot_cancel_awaiting_payment_after_success(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_confirming_twice_settles_once(): void
    {
        Storage::fake((string) config('files.disk'));
        [$client, $token, $order] = $this->activeUnpaidOrder(2_000_000);
        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", ['method' => 'bank_transfer'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
        $payment = Payment::firstOrFail();
        $admin = User::factory()->admin()->create();
        $svc = app(PaymentService::class);

        $svc->confirmOfflinePayment($payment, $admin, 'R1');
        $svc->confirmOfflinePayment(Payment::find($payment->id)->setRawAttributes(
            array_merge(Payment::find($payment->id)->getAttributes(), ['status' => 'progress']),
        ), $admin, 'R2');

        $this->assertSame(1, $order->fresh()->payouts()->count());
        $this->assertSame('R1', $payment->fresh()->reference);
    }

    /**
     * An active deal (contract accepted) whose payment is still outstanding.
     *
     * @return array{0: User, 1: string, 2: Order}
     */
    private function activeUnpaidOrder(int $priceSom): array
    {
        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid,
            'payment_due_at' => now()->addDays(3),
        ]);
        $profile = AgentProfile::factory()->create();
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'price' => $priceSom,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        return [$client, $token, $order->fresh()];
    }
}
