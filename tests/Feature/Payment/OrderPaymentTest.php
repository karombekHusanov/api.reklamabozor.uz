<?php

namespace Tests\Feature\Payment;

use App\Enums\OfferStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function enableGateway(): void
    {
        config([
            'services.multicard.enabled' => true,
            'services.multicard.base_url' => 'https://dev-mesh.multicard.uz',
            'services.multicard.application_id' => 'rhmt_test',
            'services.multicard.secret' => 'test_secret',
            'services.multicard.store_id' => '6',
            'services.multicard.callback_url' => 'https://api.test/api/v1/payment/multicard/callback',
            // Docs algorithm (sha1). Legacy md5 covered in a dedicated test.
            'services.multicard.callback_sign' => 'sha1',
            // No IP restriction in tests (requests come from 127.0.0.1).
            'services.multicard.callback_ips' => '',
        ]);
    }

    public function test_accepting_offer_creates_invoice_and_awaits_payment(): void
    {
        $this->enableGateway();

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice' => Http::response([
                'success' => true,
                'data' => ['uuid' => 'gw-uuid-1', 'checkout_url' => 'https://pay.test/gw-uuid-1'],
            ]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $chosen = Offer::factory()->for($order)->create(['price' => 3_000_000]);

        $this->postJson("/api/v1/offers/{$chosen->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.offer.status', 'accepted')
            ->assertJsonPath('data.payment.checkout_url', 'https://pay.test/gw-uuid-1')
            ->assertJsonPath('data.payment.status', 'draft');

        // Deal does NOT activate yet — it waits for payment.
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);

        $payment = Payment::first();
        $this->assertSame(3_000_000 * 100, $payment->amount); // som → tiyin
        $this->assertSame('gw-uuid-1', $payment->gateway_uuid);

        // Invoice sent with the right amount (tiyin) and our uuid as invoice_id.
        Http::assertSent(function ($request) use ($payment) {
            return str_contains($request->url(), '/payment/invoice')
                && $request['amount'] === 3_000_000 * 100
                && $request['invoice_id'] === $payment->payment_uuid
                && $request['store_id'] === '6';
        });
    }

    public function test_invoice_includes_return_urls_when_mini_app_configured(): void
    {
        $this->enableGateway();
        config(['services.telegram.mini_app_url' => 'https://app.test']);

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice' => Http::response([
                'success' => true,
                'data' => ['uuid' => 'gw-ret', 'checkout_url' => 'https://pay.test/gw-ret'],
            ]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['price' => 3_000]);

        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        Http::assertSent(function ($request) use ($order) {
            if (! str_contains($request->url(), '/payment/invoice')) {
                return false;
            }
            $data = $request->data();

            return ($data['return_url'] ?? null) === "https://app.test/orders/{$order->id}"
                && ($data['return_error_url'] ?? null) === "https://app.test/orders/{$order->id}?pay=failed";
        });
    }

    public function test_invoice_omits_return_urls_without_mini_app_url(): void
    {
        $this->enableGateway();
        config(['services.telegram.mini_app_url' => '']);

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice' => Http::response([
                'success' => true,
                'data' => ['uuid' => 'gw-noret', 'checkout_url' => 'https://pay.test/gw-noret'],
            ]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['price' => 3_000]);

        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payment/invoice')
            && ! array_key_exists('return_url', (array) $request->data())
            && ! array_key_exists('return_error_url', (array) $request->data()));
    }

    public function test_accept_survives_a_gateway_outage(): void
    {
        $this->enableGateway();
        // Multicard unreachable (DNS/connection failure) on every call.
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['price' => 3_000]);

        // Acceptance must still succeed (no 500); payment is just deferred.
        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.offer.status', 'accepted')
            ->assertJsonPath('data.payment', null);

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_pay_returns_retryable_error_on_gateway_outage(): void
    {
        $this->enableGateway();
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted, 'price' => 3_000]);

        $this->postJson("/api/v1/orders/{$order->id}/pay", [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(503);
    }

    public function test_invoice_omits_ofd_when_fiscalization_disabled(): void
    {
        $this->enableGateway();
        config(['services.multicard.ofd_enabled' => false]);

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice' => Http::response([
                'success' => true,
                'data' => ['uuid' => 'gw-noofd', 'checkout_url' => 'https://pay.test/gw-noofd'],
            ]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['price' => 3_000]);

        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], ['Authorization' => 'Bearer '.$token])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payment/invoice')
            && ! array_key_exists('ofd', (array) $request->data()));
    }

    public function test_pay_reuses_a_live_invoice(): void
    {
        $this->enableGateway();
        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice' => Http::response([
                'success' => true,
                'data' => ['uuid' => 'gw-live', 'checkout_url' => 'https://pay.test/gw-live'],
            ]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted, 'price' => 3_000]);

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson("/api/v1/orders/{$order->id}/pay", [], $headers)
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://pay.test/gw-live');
        $this->postJson("/api/v1/orders/{$order->id}/pay", [], $headers)
            ->assertOk()->assertJsonPath('data.checkout_url', 'https://pay.test/gw-live');

        // The live invoice is reused — no duplicate payment, no second invoice call.
        $this->assertSame(1, Payment::count());
    }

    public function test_pay_mints_a_fresh_invoice_when_the_previous_expired(): void
    {
        $this->enableGateway();
        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice' => Http::response([
                'success' => true,
                'data' => ['uuid' => 'gw-new', 'checkout_url' => 'https://pay.test/gw-new'],
            ]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('t')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted, 'price' => 3_000]);

        // A draft invoice created two hours ago — past the 1h ttl.
        $expired = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Draft,
            'gateway_uuid' => 'gw-old',
            'checkout_url' => 'https://pay.test/gw-old',
            'created_at' => now()->subHours(2),
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/pay", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://pay.test/gw-new'); // fresh, not the dead one

        // Old dead invoice retired; a new payment minted.
        $this->assertSame(PaymentStatus::Error, $expired->fresh()->status);
        $this->assertSame(2, Payment::count());
    }

    public function test_webhook_success_activates_the_deal(): void
    {
        $this->enableGateway();
        Http::fake();

        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        $offer = Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted, 'price' => 2_000]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'gateway_uuid' => 'gw-2',
            'amount' => 200_000,
            'status' => PaymentStatus::Draft,
        ]);

        $payload = $this->signedPayload('gw-2', $payment->payment_uuid, 200_000, 'success');

        $this->postJson('/api/v1/payment/multicard/callback', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertDatabaseHas('chats', ['order_id' => $order->id, 'agent_id' => $offer->agent_id]);
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->enableGateway();

        $order = Order::factory()->status(OrderStatus::AwaitingPayment)->create();
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'gateway_uuid' => 'gw-3',
            'amount' => 200_000,
        ]);

        $this->postJson('/api/v1/payment/multicard/callback', [
            'uuid' => 'gw-3',
            'invoice_id' => $payment->payment_uuid,
            'amount' => 200_000,
            'status' => 'success',
            'sign' => 'deadbeef',
        ])->assertForbidden();

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_webhook_rejects_amount_mismatch(): void
    {
        $this->enableGateway();

        $order = Order::factory()->status(OrderStatus::AwaitingPayment)->create();
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'gateway_uuid' => 'gw-amt',
            'amount' => 200_000,
        ]);

        // Sign is valid for the *tampered* amount, but payload ≠ our Payment.
        $tampered = 1;
        $this->postJson('/api/v1/payment/multicard/callback', [
            'uuid' => 'gw-amt',
            'invoice_id' => $payment->payment_uuid,
            'amount' => $tampered,
            'status' => 'success',
            'sign' => sha1('gw-amt'.$payment->payment_uuid.$tampered.'test_secret'),
        ])->assertForbidden()->assertJsonPath('error', 'payload_mismatch');

        $this->assertSame(PaymentStatus::Draft, $payment->fresh()->status);
    }

    public function test_webhook_accepts_legacy_md5_when_mode_is_both(): void
    {
        $this->enableGateway();
        config(['services.multicard.callback_sign' => 'both']);
        Http::fake();

        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'gateway_uuid' => 'gw-md5',
            'amount' => 200_000,
        ]);

        $payload = [
            'uuid' => 'gw-md5',
            'invoice_id' => $payment->payment_uuid,
            'amount' => 200_000,
            'status' => 'success',
            'ps' => 'uzcard',
            'sign' => md5('6'.$payment->payment_uuid.'200000'.'test_secret'),
        ];

        $this->postJson('/api/v1/payment/multicard/callback', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_webhook_null_status_fetches_gateway_and_settles(): void
    {
        // Real stand sometimes posts a signed callback with status=null; we
        // fill via getPayment instead of mapping null → Draft.
        $this->enableGateway();

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/gw-null' => Http::response(['data' => [
                'status' => 'success',
                'ps' => 'uzcard',
                'card_pan' => '860030******5959',
            ]]),
        ]);

        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-null',
            'amount' => 200_000,
            'status' => PaymentStatus::Progress,
        ]);

        $payload = $this->signedPayload('gw-null', $payment->payment_uuid, 200_000, 'success');
        unset($payload['status']);

        $this->postJson('/api/v1/payment/multicard/callback', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame('860030******5959', $payment->fresh()->card_pan);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/payment/gw-null'));
    }

    public function test_webhook_null_status_does_not_write_draft_when_gateway_empty(): void
    {
        $this->enableGateway();

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/*' => Http::response(['data' => []]),
        ]);

        $order = Order::factory()->status(OrderStatus::AwaitingPayment)->create();
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-empty',
            'amount' => 200_000,
            'status' => PaymentStatus::Progress,
        ]);

        $payload = $this->signedPayload('gw-empty', $payment->payment_uuid, 200_000, 'success');
        $payload['status'] = null;

        $this->postJson('/api/v1/payment/multicard/callback', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(PaymentStatus::Progress, $payment->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_webhook_is_idempotent(): void
    {
        $this->enableGateway();
        Http::fake();

        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'gateway_uuid' => 'gw-4',
            'amount' => 200_000,
        ]);

        $payload = $this->signedPayload('gw-4', $payment->payment_uuid, 200_000, 'success');

        $this->postJson('/api/v1/payment/multicard/callback', $payload)->assertOk();
        $this->postJson('/api/v1/payment/multicard/callback', $payload)->assertOk();

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(1, $order->chat()->count());
    }

    public function test_webhook_does_not_downgrade_success_to_error(): void
    {
        $this->enableGateway();

        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'gateway_uuid' => 'gw-mono',
            'amount' => 200_000,
            'status' => PaymentStatus::Success,
            'paid_at' => now(),
        ]);

        $this->postJson(
            '/api/v1/payment/multicard/callback',
            $this->signedPayload('gw-mono', $payment->payment_uuid, 200_000, 'error'),
        )->assertOk();

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_webhook_revert_voids_payouts_but_keeps_order_unless_admin(): void
    {
        $this->enableGateway();
        Http::fake();

        $client = User::factory()->create();
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'price' => 2_000,
        ]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'gateway_uuid' => 'gw-rev',
            'amount' => 200_000,
            'status' => PaymentStatus::Success,
            'paid_at' => now(),
        ]);
        $pending = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'amount' => 80_000,
            'status' => PayoutStatus::Pending,
        ]);
        $paid = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'amount' => 40_000,
            'status' => PayoutStatus::Paid,
            'paid_at' => now(),
        ]);

        $payload = $this->signedPayload('gw-rev', $payment->payment_uuid, 200_000, 'revert');

        $this->postJson('/api/v1/payment/multicard/callback', $payload)->assertOk();
        $this->postJson('/api/v1/payment/multicard/callback', $payload)->assertOk(); // idempotent

        $this->assertSame(PaymentStatus::Revert, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->refunded_at);
        // Unexpected revert must NOT auto-cancel — ops decides.
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame(PayoutStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame(PayoutStatus::Paid, $paid->fresh()->status);
    }

    public function test_admin_refund_cancels_order_and_calls_gateway(): void
    {
        $this->enableGateway();
        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/*' => Http::response(['success' => true, 'data' => ['status' => 'revert']]),
        ]);

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
            'gateway_uuid' => 'gw-admin-ref',
            'amount' => 200_000,
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

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/payment/gw-admin-ref')
            && ! str_contains($request->url(), '/invoice/'));
    }

    public function test_admin_refund_blocked_when_payout_already_paid(): void
    {
        $this->enableGateway();
        Http::fake();

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $order = Order::factory()->status(OrderStatus::InProgress)->create();
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'gateway_uuid' => 'gw-paid-out',
            'amount' => 200_000,
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

    public function test_expired_awaiting_payment_orders_are_cancelled(): void
    {
        $this->enableGateway();
        config(['services.multicard.awaiting_payment_timeout_hours' => 72]);

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice/*' => Http::response(['success' => true]),
        ]);

        $order = Order::factory()->status(OrderStatus::AwaitingPayment)->create([
            'awaiting_payment_at' => now()->subHours(73),
        ]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-expire',
            'status' => PaymentStatus::Draft,
            'checkout_url' => 'https://pay.test/gw-expire',
        ]);

        $fresh = Order::factory()->status(OrderStatus::AwaitingPayment)->create([
            'awaiting_payment_at' => now()->subHours(1),
        ]);

        $this->artisan('orders:cancel-expired-awaiting-payments')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Error, $payment->fresh()->status);
        $this->assertSame(OrderStatus::AwaitingPayment, $fresh->fresh()->status);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/payment/invoice/gw-expire'));
    }

    public function test_client_cancel_awaiting_payment_cancels_invoice(): void
    {
        $this->enableGateway();

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice/*' => Http::response(['success' => true]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-client-cancel',
            'status' => PaymentStatus::Draft,
            'checkout_url' => 'https://pay.test/gw-client-cancel',
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Error, $payment->fresh()->status);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/payment/invoice/gw-client-cancel'));
    }

    public function test_client_cannot_cancel_awaiting_payment_after_success(): void
    {
        $this->enableGateway();

        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-paid',
            'status' => PaymentStatus::Success,
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
    }

    public function test_reconcile_pending_settles_a_progress_payment(): void
    {
        // Multicard's callback fired at `progress` and never sent a `success`
        // follow-up — the sweep re-queries the gateway and settles the order.
        $this->enableGateway();

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/*' => Http::response(['data' => ['status' => 'success', 'ps' => 'uzcard', 'card_pan' => '860030******5959']]),
        ]);

        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-recon',
            'amount' => 200_000,
            'status' => PaymentStatus::Progress,
        ]);

        $this->artisan('payments:reconcile-pending')->assertSuccessful();

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function signedPayload(string $uuid, string $invoiceId, int $amount, string $status): array
    {
        return [
            'uuid' => $uuid,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'status' => $status,
            'ps' => 'uzcard',
            'card_pan' => '860030******5959',
            // Docs callback-webhooks: sha1(uuid + invoice_id + amount + secret)
            'sign' => sha1($uuid.$invoiceId.(string) $amount.config('services.multicard.secret')),
        ];
    }
}
