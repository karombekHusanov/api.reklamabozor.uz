<?php

namespace Tests\Feature\Finance;

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        config(['services.multicard.commission_percent' => 7]);

        $profile = AgentProfile::factory()->create([
            'company_name' => 'MIRON',
            'bank_name' => 'Ipoteka Bank',
            'bank_account' => '20208000900001234567',
            'mfo' => '00842',
        ]);

        $order = Order::factory()->status(OrderStatus::Completed)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now()->subDay(),
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
            'method' => PaymentMethod::Multicard,
            'amount' => $priceSom * 100,
            'paid_at' => now()->subDay(),
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
        $this->assertSame('multicard', $response->json('data.collected_by_method.0.method'));
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
        $this->assertStringContainsString('multicard', $csv);
    }

    public function test_registers_are_admin_only(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->getJson('/api/v1/admin/finance/summary', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/finance/payouts.csv', $headers)->assertStatus(403);
    }
}
