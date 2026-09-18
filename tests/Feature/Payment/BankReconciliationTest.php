<?php

namespace Tests\Feature\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AgentProfile;
use App\Models\Contract;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payment\BankReconciliationService;
use App\Services\Payment\KapitalBankClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_match_confirms_the_payment_and_settles_the_order(): void
    {
        [$order, $payment, $contractNumber] = $this->activeBankTransferOrder(2_000_000);

        $this->mockStatement([
            $this->incomingRow($contractNumber, $payment->amount),
        ]);

        app(BankReconciliationService::class)->reconcile();

        $fresh = $payment->fresh();
        $this->assertSame(PaymentStatus::Success, $fresh->status);
        $this->assertSame('auto', $fresh->matched_via);
        $this->assertNull($fresh->confirmed_by);
        $this->assertNotNull($fresh->paid_at);
        $this->assertStringContainsString($contractNumber, (string) $fresh->reference);

        $orderFresh = $order->fresh();
        $this->assertSame(OrderPaymentState::Paid, $orderFresh->payment_state);
        // Money is in — the agent's advance payout is planned, same as a
        // manual admin confirm would do (settlement path is shared).
        $this->assertSame(1, $orderFresh->payouts()->count());
    }

    public function test_amount_mismatch_does_not_confirm_the_payment(): void
    {
        [, $payment, $contractNumber] = $this->activeBankTransferOrder(2_000_000);

        $this->mockStatement([
            $this->incomingRow($contractNumber, $payment->amount + 100),
        ]);

        app(BankReconciliationService::class)->reconcile();

        $this->assertSame(PaymentStatus::Progress, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->matched_via);
    }

    public function test_ambiguous_double_match_confirms_neither_payment(): void
    {
        [, $paymentA, $contractA] = $this->activeBankTransferOrder(1_000_000);
        [, $paymentB, $contractB] = $this->activeBankTransferOrder(1_000_000);

        // A single incoming row's purpose happens to contain both contract
        // numbers (or a duplicate amount+reference edge case) — same amount
        // as both pending payments. Neither may be guessed.
        $ambiguousPurpose = "To'lov shartnoma {$contractA} / {$contractB}";

        $this->mockStatement([
            [
                'dir' => 2,
                'purpose' => $ambiguousPurpose,
                'amount' => $paymentA->amount,
            ],
        ]);

        app(BankReconciliationService::class)->reconcile();

        $this->assertSame(PaymentStatus::Progress, $paymentA->fresh()->status);
        $this->assertSame(PaymentStatus::Progress, $paymentB->fresh()->status);
        $this->assertNull($paymentA->fresh()->matched_via);
        $this->assertNull($paymentB->fresh()->matched_via);
    }

    public function test_no_pending_payments_skips_the_bank_api_call(): void
    {
        config(['kapitalbank.enabled' => true]);

        $this->mock(KapitalBankClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getStatementForDaysAgo');
            $mock->shouldNotReceive('getStatement');
        });

        // No BankTransfer payments in Draft/Progress exist.
        app(BankReconciliationService::class)->reconcile();

        $this->assertTrue(true); // reaching here without a mock failure is the assertion
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function mockStatement(array $rows): void
    {
        config(['kapitalbank.enabled' => true, 'kapitalbank.poll_lookback_days' => 1]);

        $this->mock(KapitalBankClient::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('getStatementForDaysAgo')->once()->with(0)->andReturn($rows);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function incomingRow(string $contractNumber, int $amountTiyin): array
    {
        return [
            'dir' => 2,
            'purpose' => "To'lov shartnoma {$contractNumber} bo'yicha",
            'amount' => $amountTiyin,
        ];
    }

    /**
     * An active deal (accepted offer) with a bank-transfer payment sitting in
     * Progress, plus the contract whose number is the match key.
     *
     * @return array{0: Order, 1: Payment, 2: string}
     */
    private function activeBankTransferOrder(int $priceSom): array
    {
        $client = User::factory()->create();
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

        $contractNumber = 'RB-'.$order->id.'-2026';
        Contract::create([
            'order_id' => $order->id,
            'number' => $contractNumber,
            'client_snapshot' => [],
            'agent_snapshot' => [],
            'items_snapshot' => [],
        ]);

        $payment = Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Progress,
            'amount' => $priceSom * 100,
            'gateway_uuid' => null,
            'checkout_url' => null,
        ]);

        return [$order->fresh(), $payment, $contractNumber];
    }
}
