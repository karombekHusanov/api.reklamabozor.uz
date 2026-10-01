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

        $this->assertEquals(1_000_000, $response->json('data.collected.som'));
        $this->assertEquals(0, $response->json('data.refunded.som'));
        // 7% of the completed deal.
        $this->assertEquals(70_000, $response->json('data.commission.som'));
        $this->assertEquals(372_000, $response->json('data.paid_to_agents.som'));
        $this->assertSame('bank_transfer', $response->json('data.collected_by_method.0.method'));
        $this->assertSame(1, $response->json('data.counts.orders_completed'));
    }

    public function test_summary_respects_the_period(): void
    {
        $this->settledDeal();

        $response = $this->getJson(
            '/api/v1/admin/finance/summary?from='.now()->addMonth()->toDateString()
                .'&to='.now()->addMonths(2)->toDateString(),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertEquals(0, $response->json('data.collected.som'));
        $this->assertEquals(0, $response->json('data.paid_to_agents.som'));
    }

    public function test_unpaid_active_orders_show_up_as_receivables(): void
    {
        $order = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid,
        ]);
        Offer::factory()->for($order)->accepted()->create(['price' => 500_000]);

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        $this->assertEquals(500_000, $response->json('data.receivables.som'));
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

        $this->assertEquals(1_400_000, $response->json('data.collected.som'));
        $this->assertEquals(400_000, $response->json('data.refunded.som'));
        $this->assertEquals(1_000_000, $response->json('data.net_collected.som'));
        $this->assertSame(2, $response->json('data.counts.payments'));
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

        $this->assertEquals(100_000, $sep5->json('data.collected.som'));
        $this->assertEquals(0, $sep4->json('data.collected.som'));
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

    public function test_summary_reports_propusk_revenue_apart_from_orders(): void
    {
        $this->propuskActivity();

        $response = $this->getJson('/api/v1/admin/finance/summary', $this->adminHeaders())->assertOk();

        // Revenue: two sold passes + three responses; the free grant is not money.
        $this->assertEquals(23_000, $response->json('data.passes.revenue.som'));
        $this->assertEquals(20_000, $response->json('data.passes.pass_sales.som'));
        $this->assertSame(2, $response->json('data.passes.pass_sales.count'));
        $this->assertEquals(3_000, $response->json('data.passes.response_fees.som'));
        $this->assertSame(3, $response->json('data.passes.response_fees.count'));
        // Cash that came in by card; the failed top-up is not counted.
        $this->assertEquals(60_000, $response->json('data.passes.card_collected.som'));
        $this->assertEquals(10_000, $response->json('data.passes.card_collected_by_purpose.pass.som'));
        $this->assertEquals(50_000, $response->json('data.passes.card_collected_by_purpose.topup.som'));
        // Prepaid and not yet spent.
        $this->assertEquals(37_000, $response->json('data.passes.wallet_balance.som'));
        // Order money is untouched.
        $this->assertEquals(0, $response->json('data.collected.som'));
    }

    public function test_propusk_revenue_respects_the_period(): void
    {
        $this->propuskActivity();

        $response = $this->getJson(
            '/api/v1/admin/finance/summary?from='.now()->addMonth()->toDateString()
                .'&to='.now()->addMonths(2)->toDateString(),
            $this->adminHeaders(),
        )->assertOk();

        $this->assertEquals(0, $response->json('data.passes.revenue.som'));
        $this->assertEquals(0, $response->json('data.passes.card_collected.som'));
        // A balance is a position as of now, not a period flow.
        $this->assertEquals(37_000, $response->json('data.passes.wallet_balance.som'));
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

    public function test_payments_register_lists_settled_money(): void
    {
        $this->settledDeal();

        $csv = $this->get('/api/v1/admin/finance/payments.csv?status=success', $this->adminHeaders())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('1000000.00', $csv);
        $this->assertStringContainsString('bank_transfer', $csv);
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
