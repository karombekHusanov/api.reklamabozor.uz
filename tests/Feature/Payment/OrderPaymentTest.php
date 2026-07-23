<?php

namespace Tests\Feature\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'sign' => md5(config('services.multicard.store_id').$invoiceId.$amount.config('services.multicard.secret')),
        ];
    }
}
