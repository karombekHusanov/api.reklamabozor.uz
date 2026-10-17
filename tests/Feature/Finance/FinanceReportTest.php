<?php

namespace Tests\Feature\Finance;

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\Role;
use App\Models\AgentPass;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderProblemResolution;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The finance overview and the two registers the bookkeeping works from.
 */
class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    /** A completed, settled deal: one payment in, one payout out. */
    private function settledDeal(int $priceSom = 1_000_000): Order
    {
        config(['payments.commission_percent' => 7]);

        $profile = AgentProfile::factory()->create([
            'company_name' => 'MIRON',
            'bank_name' => 'Ipoteka Bank',
            'bank_account' => '20208000900001234567',
            'mfo' => '00842',
        ]);

        $order = Order::factory()->status(OrderStatus::Completed)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        Offer::factory()->for($order)->accepted()->create([
            'price' => $priceSom,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'method' => PaymentMethod::BankTransfer,
            'amount' => $priceSom * 100,
            'paid_at' => now(),
        ]);

        Payout::factory()->create([
            'order_id' => $order->id,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'status' => PayoutStatus::Paid,
            'amount' => 37_200_000,
            'paid_at' => now(),
            'reference' => 'PO-77',
        ]);

        return $order;
    }

    public function test_summary_reports_the_period_totals(): void
    {
        $this->settledDeal();

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())
            ->assertOk();

        $this->assertEquals(1_000_000, $response->json('data.orders.collected.som'));
        $this->assertSame(1, $response->json('data.orders.collected.count'));
        $this->assertEquals(0, $response->json('data.orders.refunded.som'));
        $this->assertEquals(1_000_000, $response->json('data.orders.net_collected.som'));
        // 7% of the completed, paid deal.
        $this->assertEquals(70_000, $response->json('data.orders.commission.som'));
        $this->assertSame(1, $response->json('data.orders.commission.deals'));
        $this->assertEquals(372_000, $response->json('data.orders.paid_to_agents.som'));
        $this->assertSame(1, $response->json('data.orders.paid_to_agents.count'));
        $this->assertSame('bank_transfer', $response->json('data.orders.collected_by_method.0.method'));
        $this->assertSame(1, $response->json('data.orders.deals_completed'));
    }

    public function test_summary_respects_the_period(): void
    {
        $this->settledDeal();

        $response = $this->getJson(
            '/api/v1/admin/finance/summary?from='.now()->addMonth()->toDateString()
                .'&to='.now()->addMonths(2)->toDateString(),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertEquals(0, $response->json('data.orders.collected.som'));
        $this->assertEquals(0, $response->json('data.orders.paid_to_agents.som'));
        $this->assertEquals(0, $response->json('data.orders.commission.som'));
    }

    public function test_commission_is_only_earned_on_paid_deals(): void
    {
        config(['payments.commission_percent' => 7]);

        // Closed with the gateway off: nothing was collected, nothing earned.
        $free = Order::factory()->status(OrderStatus::Completed)->create([
            'payment_state' => OrderPaymentState::NotRequired,
            'completed_at' => now(),
        ]);
        Offer::factory()->for($free)->accepted()->create(['price' => 900_000]);

        // Closed before the client settled: the final payout is held, no commission yet.
        $owed = Order::factory()->status(OrderStatus::Completed)->create([
            'payment_state' => OrderPaymentState::Unpaid,
            'completed_at' => now(),
        ]);
        Offer::factory()->for($owed)->accepted()->create(['price' => 300_000]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(0, $response->json('data.orders.commission.som'));
        $this->assertSame(0, $response->json('data.orders.commission.deals'));
        $this->assertSame(2, $response->json('data.orders.deals_completed'));
        // ...but the unpaid one is a receivable.
        $this->assertEquals(300_000, $response->json('data.positions.receivables.som'));
        $this->assertSame(1, $response->json('data.positions.receivables.orders'));
    }

    public function test_unpaid_active_orders_show_up_as_receivables(): void
    {
        $order = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid,
        ]);
        Offer::factory()->for($order)->accepted()->create(['price' => 500_000]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(500_000, $response->json('data.positions.receivables.som'));
        $this->assertSame(1, $response->json('data.positions.receivables.orders'));
    }

    public function test_pending_payouts_are_split_into_releasable_and_locked(): void
    {
        config(['orders.paid_cancel_window_minutes' => 60]);

        // Paid two hours ago — the cooling-off window is closed, money may leave.
        $settled = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now()->subHours(2),
        ]);
        Payout::factory()->create(['order_id' => $settled->id, 'amount' => 40_000_000]);

        // Paid just now — the client can still cancel, the payout is locked.
        $fresh = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now(),
        ]);
        Payout::factory()->create(['order_id' => $fresh->id, 'amount' => 10_000_000]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(500_000, $response->json('data.positions.pending_payouts.som'));
        $this->assertSame(2, $response->json('data.positions.pending_payouts.count'));
        $this->assertEquals(400_000, $response->json('data.positions.releasable_payouts.som'));
        $this->assertSame(1, $response->json('data.positions.releasable_payouts.count'));
    }

    public function test_a_refund_is_subtracted_once_from_the_net(): void
    {
        $this->settledDeal();

        // 400k came in and was handed back later in the same period.
        Payment::factory()->create([
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Revert,
            'method' => PaymentMethod::Cash,
            'amount' => 40_000_000,
            'paid_at' => now(),
            'refunded_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(1_400_000, $response->json('data.orders.collected.som'));
        $this->assertEquals(400_000, $response->json('data.orders.refunded.som'));
        $this->assertEquals(1_000_000, $response->json('data.orders.net_collected.som'));
        $this->assertSame(2, $response->json('data.orders.collected.count'));
    }

    public function test_hand_refunds_through_addenda_and_problem_orders_count_as_refunded(): void
    {
        $order = $this->settledDeal();

        // An applied addendum cut the price by 150k and a manager returned it.
        OrderAmendment::create([
            'order_id' => $order->id,
            'offer_id' => $order->acceptedOffer()->firstOrFail()->id,
            'initiator_id' => $order->client_id,
            'initiator_role' => 'client',
            'before_snapshot' => ['items' => [], 'total' => 1_000_000],
            'after_snapshot' => ['items' => [], 'total' => 850_000],
            'extra_amount' => -150_000,
            'status' => 'applied',
            'refund_amount' => 150_000,
            'refund_state' => OrderAmendment::REFUND_REFUNDED,
            'refunded_at' => now(),
        ]);

        // A problem-order settlement handed 50k back by hand.
        OrderProblemResolution::create([
            'order_id' => $order->id,
            'resolution' => OrderProblemResolution::REFUNDED,
            'refund_amount' => 5_000_000,
            'refund_method' => 'cash',
            'resolved_by' => User::factory()->create(['role' => Role::Admin])->id,
            'resolved_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(200_000, $response->json('data.orders.refunded.som'));
        $this->assertSame(2, $response->json('data.orders.refunded.count'));
        $this->assertEquals(0, $response->json('data.orders.refunded_by_source.payments.som'));
        $this->assertEquals(150_000, $response->json('data.orders.refunded_by_source.amendments.som'));
        $this->assertEquals(50_000, $response->json('data.orders.refunded_by_source.problems.som'));
        $this->assertEquals(800_000, $response->json('data.orders.net_collected.som'));
    }

    public function test_the_period_is_a_tashkent_calendar_day(): void
    {
        // 02:00 on 5 Sep in Tashkent is still 4 Sep in UTC.
        Payment::factory()->create([
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'method' => PaymentMethod::Cash,
            'amount' => 10_000_000,
            'paid_at' => '2026-09-04 21:00:00',
        ]);

        $sep5 = $this->getJson('/api/v1/admin/finance/summary?from=2026-09-05&to=2026-09-05', $this->adminHeaders());
        $sep4 = $this->getJson('/api/v1/admin/finance/summary?from=2026-09-04&to=2026-09-04', $this->adminHeaders());

        $this->assertEquals(100_000, $sep5->json('data.orders.collected.som'));
        $this->assertEquals(0, $sep4->json('data.orders.collected.som'));
    }

    /**
     * One agent in the period: buys a pass by card (10k), tops up 50k, then
     * spends 10k of it on a pass and 3k on three responses; plus a free grant.
     */
    private function propuskActivity(): User
    {
        $agent = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'Agent', 'phone' => '+998901112233']);

        $cardPass = GatewayPayment::create([
            'reference' => (string) Str::uuid(), 'user_id' => $agent->id,
            'purpose' => 'pass', 'amount_tiyin' => 1_000_000, 'status' => 'success',
            'gateway' => 'atmos', 'gateway_ref' => 'card:777', 'paid_at' => now(),
            'meta' => ['card_mask' => '8600 •••• 2365'],
        ]);
        GatewayPayment::create([
            'reference' => (string) Str::uuid(), 'user_id' => $agent->id,
            'purpose' => 'topup', 'amount_tiyin' => 5_000_000, 'status' => 'success',
            'gateway' => 'atmos', 'paid_at' => now(),
        ]);
        // Never paid — carries no money.
        GatewayPayment::create([
            'reference' => (string) Str::uuid(), 'user_id' => $agent->id,
            'purpose' => 'topup', 'amount_tiyin' => 9_000_000, 'status' => 'failed', 'gateway' => 'atmos',
        ]);

        $pass = fn (int $price, string $source, ?int $gatewayPaymentId = null) => AgentPass::create([
            'user_id' => $agent->id, 'starts_at' => now(), 'expires_at' => now()->addDay(),
            'price_tiyin' => $price, 'source' => $source, 'gateway_payment_id' => $gatewayPaymentId,
        ]);
        $pass(1_000_000, 'gateway', $cardPass->id);
        $pass(1_000_000, 'wallet');
        $pass(0, 'admin');

        $wallet = fn (string $type, int $amount) => WalletTransaction::create([
            'user_id' => $agent->id, 'type' => $type, 'amount_tiyin' => $amount,
        ]);
        $wallet('topup', 5_000_000);
        $wallet('pass', -1_000_000);
        foreach (range(1, 3) as $i) {
            $wallet('response_fee', -100_000);
        }

        return $agent;
    }

    public function test_summary_reports_agent_fees_apart_from_orders(): void
    {
        $this->propuskActivity();

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        // Revenue: two sold passes + three responses; the free grant is not money.
        $this->assertEquals(23_000, $response->json('data.fees.revenue.som'));
        $this->assertEquals(20_000, $response->json('data.fees.pass_sales.som'));
        $this->assertSame(2, $response->json('data.fees.pass_sales.count'));
        $this->assertEquals(3_000, $response->json('data.fees.response_fees.som'));
        $this->assertSame(3, $response->json('data.fees.response_fees.count'));
        $this->assertEquals(0, $response->json('data.fees.take_fees.som'));
        // Cash that came in by card; the failed top-up is not counted.
        $this->assertEquals(60_000, $response->json('data.fees.card_collected.som'));
        $this->assertSame(2, $response->json('data.fees.card_collected.count'));
        $this->assertEquals(0, $response->json('data.fees.card_refunds.som'));
        // Prepaid and not yet spent — a balance, under positions.
        $this->assertEquals(37_000, $response->json('data.positions.agent_balances.som'));
        $this->assertSame(1, $response->json('data.positions.agent_balances.agents'));
        // Order money is untouched.
        $this->assertEquals(0, $response->json('data.orders.collected.som'));
    }

    public function test_take_fees_and_card_refunds_are_reported_separately(): void
    {
        $agent = $this->propuskActivity();

        WalletTransaction::create(['user_id' => $agent->id, 'type' => 'take_fee', 'amount_tiyin' => -500_000]);
        // A 50k top-up reversed to the card: the wallet gives the money back.
        WalletTransaction::create(['user_id' => $agent->id, 'type' => 'refund', 'amount_tiyin' => -5_000_000]);
        GatewayPayment::create([
            'reference' => (string) Str::uuid(), 'user_id' => $agent->id,
            'purpose' => 'topup', 'amount_tiyin' => 5_000_000, 'status' => 'refunded',
            'gateway' => 'atmos', 'paid_at' => now(),
        ]);
        WalletTransaction::create(['user_id' => $agent->id, 'type' => 'adjustment', 'amount_tiyin' => 200_000]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(5_000, $response->json('data.fees.take_fees.som'));
        $this->assertSame(1, $response->json('data.fees.take_fees.count'));
        $this->assertEquals(28_000, $response->json('data.fees.revenue.som'));
        // Gross card money still includes the reversed top-up; the refund is its own line.
        $this->assertEquals(110_000, $response->json('data.fees.card_collected.som'));
        $this->assertEquals(50_000, $response->json('data.fees.card_refunds.som'));
        $this->assertEquals(2_000, $response->json('data.fees.adjustments.som'));
        $this->assertSame(1, $response->json('data.fees.adjustments.count'));
        $this->assertEquals(-16_000, $response->json('data.positions.agent_balances.som'));
    }

    public function test_agent_fees_respect_the_period(): void
    {
        $this->propuskActivity();

        $response = $this->getJson(
            '/api/v1/admin/finance/summary?from='.now()->addMonth()->toDateString()
                .'&to='.now()->addMonths(2)->toDateString(),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertEquals(0, $response->json('data.fees.revenue.som'));
        $this->assertEquals(0, $response->json('data.fees.card_collected.som'));
        // A balance is a position as of now, not a period flow.
        $this->assertEquals(37_000, $response->json('data.positions.agent_balances.som'));
    }

    public function test_gateway_payments_register_lists_card_money(): void
    {
        $this->propuskActivity();

        $csv = $this->get('/api/v1/admin/finance/gateway-payments.csv', $this->adminHeaders())
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Aziz Agent', $csv);
        $this->assertStringContainsString('10000.00', $csv);
        $this->assertStringContainsString('50000.00', $csv);
        $this->assertStringContainsString('8600 •••• 2365', $csv);
        $this->assertStringNotContainsString('90000.00', $csv);
    }

    public function test_payouts_register_carries_the_bank_requisites(): void
    {
        $this->settledDeal();

        $csv = $this->get('/api/v1/admin/finance/payouts.csv', $this->adminHeaders())
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('MIRON', $csv);
        $this->assertStringContainsString('20208000900001234567', $csv);
        $this->assertStringContainsString('00842', $csv);
        $this->assertStringContainsString('372000.00', $csv);
        $this->assertStringContainsString('PO-77', $csv);
    }

    public function test_payments_register_lists_settled_money_only_by_default(): void
    {
        $this->settledDeal();

        // An invoice the client never paid — not money.
        Payment::factory()->create([
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Draft,
            'method' => PaymentMethod::Cash,
            'amount' => 77_700_000,
        ]);

        $csv = $this->get('/api/v1/admin/finance/payments.csv', $this->adminHeaders())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('1000000.00', $csv);
        $this->assertStringContainsString('bank_transfer', $csv);
        $this->assertStringNotContainsString('777000.00', $csv);

        $all = $this->get('/api/v1/admin/finance/payments.csv?status=all', $this->adminHeaders())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('777000.00', $all);
    }

    public function test_registers_print_tashkent_time(): void
    {
        Payment::factory()->create([
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'method' => PaymentMethod::Cash,
            'amount' => 10_000_000,
            'created_at' => '2026-09-04 21:00:00',
            'paid_at' => '2026-09-04 21:30:00',
        ]);

        $csv = $this->get('/api/v1/admin/finance/payments.csv?from=2026-09-05&to=2026-09-05', $this->adminHeaders())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('05.09.2026 02:00', $csv);
        $this->assertStringContainsString('05.09.2026 02:30', $csv);
    }

    public function test_registers_are_admin_only(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->getJson('/api/v1/admin/finance/summary', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/finance/payouts.csv', $headers)->assertStatus(403);
    }
}
