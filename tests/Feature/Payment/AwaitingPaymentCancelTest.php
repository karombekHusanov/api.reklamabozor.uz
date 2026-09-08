<?php

namespace Tests\Feature\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Full matrix for client cancel while order is awaiting_payment:
 * order/invoice/offer/chat/payout + how the agent still sees the offer.
 */
class AwaitingPaymentCancelTest extends TestCase
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
            'services.multicard.callback_sign' => 'both',
            'services.multicard.callback_ips' => '',
            // Quiet Telegram / ops during cancel side-effects.
            'services.telegram.admin_chat_id' => '',
            'services.telegram.bot_token' => 'test-token',
        ]);
    }

    /**
     * @return array{0: User, 1: string, 2: Order, 3: Offer, 4: Offer, 5: Payment}
     */
    private function awaitingFixture(string $paymentStatus = 'draft'): array
    {
        $this->enableGateway();

        // Catch Multicard + any stray Telegram calls.
        Http::fake([
            '*/payment/invoice/*' => Http::response(['success' => true]),
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*' => Http::response(['ok' => true]),
        ]);

        $client = User::factory()->create();
        $winner = User::factory()->create(['role' => 'agent']);
        $loser = User::factory()->create(['role' => 'agent']);

        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create([
            'awaiting_payment_at' => now(),
        ]);

        $accepted = Offer::factory()->for($order)->for($winner, 'agent')->create([
            'status' => OfferStatus::Accepted,
            'price' => 1_500,
        ]);
        $rejected = Offer::factory()->for($order)->for($loser, 'agent')->create([
            'status' => OfferStatus::Rejected,
            'price' => 2_000,
        ]);

        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-await-cancel',
            'amount' => 150_000,
            'status' => $paymentStatus === 'progress' ? PaymentStatus::Progress : PaymentStatus::Draft,
            'checkout_url' => 'https://pay.test/gw-await-cancel',
        ]);

        $token = $client->createToken('test')->plainTextToken;

        return [$client, $token, $order, $accepted, $rejected, $payment];
    }

    public function test_client_cancel_settles_order_invoice_offers_and_hides_from_client(): void
    {
        [, $token, $order, $accepted, $rejected, $payment] = $this->awaitingFixture();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Error, $payment->fresh()->status);
        $this->assertSame(OfferStatus::Accepted, $accepted->fresh()->status);
        $this->assertSame(OfferStatus::Rejected, $rejected->fresh()->status);

        $this->assertDatabaseMissing('chats', ['order_id' => $order->id]);
        $this->assertDatabaseCount('payouts', 0);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/payment/invoice/gw-await-cancel'));

        // Client list + detail hide cancelled orders.
        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }

    public function test_client_cancel_also_errors_progress_payment(): void
    {
        [, $token, $order, , , $payment] = $this->awaitingFixture('progress');

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Error, $payment->fresh()->status);
    }

    public function test_agent_still_sees_accepted_offer_under_cancelled_order(): void
    {
        [, $token, $order, $accepted] = $this->awaitingFixture();
        $winner = $accepted->agent()->first();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertDatabaseHas('offers', [
            'id' => $accepted->id,
            'agent_id' => $winner->id,
            'status' => OfferStatus::Accepted->value,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Cancelled->value,
        ]);

        // Agent workspace: offer still listed (UI "Rejected" filter = accepted + cancelled).
        $this->actingAs($winner, 'sanctum')
            ->getJson('/api/v1/agent/offers')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $accepted->id,
                'status' => 'accepted',
            ])
            ->assertJsonFragment([
                'id' => $order->id,
                'status' => 'cancelled',
            ]);

        // Cancelled order is no longer an open bid opportunity.
        $available = $this->actingAs($winner, 'sanctum')
            ->getJson('/api/v1/agent/orders')
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($available)->contains(fn ($row) => (int) ($row['id'] ?? 0) === $order->id));
    }

    public function test_losing_agent_offer_stays_rejected_after_cancel(): void
    {
        [, $token, $order, , $rejected] = $this->awaitingFixture();
        $loser = $rejected->agent()->first();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->actingAs($loser, 'sanctum')
            ->getJson('/api/v1/agent/offers')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $rejected->id,
                'status' => 'rejected',
            ])
            ->assertJsonFragment([
                'id' => $order->id,
                'status' => 'cancelled',
            ]);
    }

    public function test_cannot_cancel_after_payment_success(): void
    {
        [, $token, $order, , , $payment] = $this->awaitingFixture();
        $payment->update(['status' => PaymentStatus::Success, 'paid_at' => now()]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
    }

    public function test_active_unpaid_deal_can_still_be_cancelled(): void
    {
        // Deals activate on contract acceptance, so an in-progress order whose
        // payment never arrived stays cancellable (CancelActiveOrderTest covers
        // the paid case and its cooling-off window).
        $this->enableGateway();
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid,
        ]);
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_second_cancel_is_rejected(): void
    {
        [, $token, $order] = $this->awaitingFixture();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        // Order is cancelled — client detail is 404; cancel endpoint still
        // resolves the model but refuses non-awaiting / non-open statuses.
        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_winner_cannot_submit_work_after_unpaid_cancel(): void
    {
        [, $token, $order, $accepted] = $this->awaitingFixture();
        $winner = $accepted->agent()->first();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        // Winner still "owns" the accepted offer, but order is no longer in_progress.
        $this->actingAs($winner, 'sanctum')
            ->postJson("/api/v1/agent/orders/{$order->id}/submit-work")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');
    }

    public function test_invoice_delete_failure_still_cancels_order(): void
    {
        $this->enableGateway();

        Http::fake([
            '*/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addDay()->toDateTimeString()]),
            '*/payment/invoice/*' => Http::response(['success' => false, 'error' => 'gone'], 400),
            '*' => Http::response(['ok' => true]),
        ]);

        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'gateway_uuid' => 'gw-fail-delete',
            'status' => PaymentStatus::Draft,
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Error, $payment->fresh()->status);
    }
}
