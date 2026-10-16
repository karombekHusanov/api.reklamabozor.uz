<?php

namespace Tests\Feature\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\WalletTransactionType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Pass\WalletService;
use App\Services\Payment\PaymentService;
use App\Services\Payout\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The money-moving transitions re-check their preconditions under a row lock
 * inside one transaction (audit 2026-10-16 §2.5). SQLite has no real row
 * locks, so these tests pin the observable rule each lock protects: the
 * second actor sees the committed state and is refused, with nothing charged.
 */
class TransitionGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config([
            'payments.enabled' => true,
            'payments.commission_percent' => 7,
            'payments.advance_percent' => 40,
        ]);
    }

    private function auth(User $user): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_only_one_offer_can_be_accepted_on_an_order(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $first = Offer::factory()->for($order)->create(['price' => 1_000_000]);
        $second = Offer::factory()->for($order)->create(['price' => 900_000]);

        $this->postJson("/api/v1/offers/{$first->id}/accept", ['accept_contract' => true], $this->auth($client))
            ->assertOk();

        // The second accept lands on an order that is already in progress.
        $this->postJson("/api/v1/offers/{$second->id}/accept", ['accept_contract' => true], $this->auth($client))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(OfferStatus::Accepted, $first->fresh()->status);
        $this->assertSame(OfferStatus::Rejected, $second->fresh()->status);
        $this->assertSame(1, $order->chat()->count());
        $this->assertSame($first->agent_id, $order->chat()->first()->agent_id);
    }

    public function test_an_otklik_on_a_closed_request_is_refused_and_not_charged(): void
    {
        config(['passes.enforce' => true, 'passes.wallet_enabled' => true, 'passes.response_price_som' => 1000]);

        $category = Category::factory()->create();
        $agent = User::factory()->create();
        AgentProfile::factory()->for($agent)->approved()->create()->categories()->attach($category);
        app(WalletService::class)->credit($agent, WalletTransactionType::Topup, 500_000, 'test');

        // Closed a moment before the otklik arrives ("Kelishildi" / cancel).
        $order = Order::factory()->for($category)->status(OrderStatus::Completed)->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(0, $order->offers()->count());
        $this->assertSame(500_000, app(WalletService::class)->balanceTiyin($agent));
    }

    public function test_a_confirmed_payment_cannot_be_rejected_and_a_reverted_one_cannot_be_refunded_again(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid,
            'activated_at' => now(),
        ]);
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'price' => 2_000_000,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);
        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $order->client_id,
            'purpose' => PaymentPurpose::Order,
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Progress,
            'amount' => 200_000_000,
        ]);

        $payments = app(PaymentService::class);
        $payments->confirmOfflinePayment($payment, $admin, 'REF-1');

        // Reject after confirm: refused, the payment stays settled.
        try {
            $payments->rejectOfflinePayment($payment->fresh(), $admin, 'late');
            $this->fail('A settled payment must not be rejected.');
        } catch (ValidationException) {
        }
        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(OrderPaymentState::Paid, $order->fresh()->payment_state);

        // Refund once: reverts and cancels; a second refund is refused.
        $payments->refundByAdmin($payment->fresh(), $admin);
        $this->assertSame(PaymentStatus::Revert, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        $this->expectException(ValidationException::class);
        $payments->refundByAdmin($payment->fresh(), $admin);
    }

    public function test_release_is_refused_twice_and_above_the_agent_net(): void
    {
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now()->subHours(2),
        ]);
        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'amount' => 1_000_000_000,
            'paid_at' => now()->subHours(2),
        ]);
        Offer::factory()->for($order)->create([
            'status' => OfferStatus::Accepted,
            'price' => 10_000_000,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        $payouts = app(PayoutService::class);
        $advance = $payouts->planAdvance($order->fresh());
        $manager = User::factory()->admin()->create();

        // Net owed on a 10 mln deal at 7% = 9.3 mln som; more than that is refused.
        try {
            $payouts->release($advance, $manager, ['amount' => 931_000_000, 'reference' => 'PO-1']);
            $this->fail('An override above the agent net must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        $this->assertSame(PayoutStatus::Pending, $advance->fresh()->status);

        $payouts->release($advance->fresh(), $manager, ['amount' => 400_000_000, 'reference' => 'PO-1']);
        $this->assertSame(PayoutStatus::Paid, $advance->fresh()->status);

        // A second manager releasing the same payout is refused.
        $this->expectException(ValidationException::class);
        $payouts->release($advance->fresh(), $manager, ['reference' => 'PO-2']);
    }

    public function test_release_is_refused_on_a_cancelled_order(): void
    {
        $profile = AgentProfile::factory()->create();
        $order = Order::factory()->status(OrderStatus::Cancelled)->create([
            'payment_state' => OrderPaymentState::Refunded,
            'paid_at' => now()->subHours(2),
        ]);
        $payout = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        // Neither the "ready to pay" queue nor a release may touch it.
        $this->assertSame(0, Payout::query()->releasable()->count());

        $this->expectException(ValidationException::class);
        app(PayoutService::class)->release($payout, User::factory()->admin()->create(), ['reference' => 'PO-9']);
    }
}
