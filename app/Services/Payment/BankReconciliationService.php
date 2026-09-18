<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Auto-matches incoming Kapitalbank transfers to pending `bank_transfer`
 * Payments by contract number (substring of the transfer's free-text purpose)
 * + exact amount. Zero-tolerance matching by design — no fuzzy/partial amount
 * matches, and any ambiguity (a row or a payment with more than one candidate)
 * is left for manual admin resolution rather than guessed.
 */
class BankReconciliationService
{
    public function __construct(
        private readonly KapitalBankClient $client,
        private readonly PaymentService $payments,
    ) {}

    public function reconcile(): void
    {
        $pending = Payment::query()
            ->where('method', PaymentMethod::BankTransfer)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->with('payable')
            ->get();

        if ($pending->isEmpty()) {
            // Never hit the bank API when there is nothing to match.
            return;
        }

        $rows = $this->fetchStatementRows();

        if ($rows === []) {
            return;
        }

        // Only money coming in counts as a candidate match.
        $incoming = array_values(array_filter(
            $rows,
            fn (array $row): bool => (int) ($row['dir'] ?? 0) === 2,
        ));

        if ($incoming === []) {
            return;
        }

        // Phase 1: for every pending payment with a contract number, find
        // every statement row it could match (substring + exact amount).
        // Computed up front (not row-by-row as we go) so that a row claimed
        // by two different payments is detected regardless of iteration
        // order — see the ambiguity rule below.
        $paymentCandidates = [];
        $rowClaims = [];

        foreach ($pending as $payment) {
            $order = $payment->payable;

            if (! $order instanceof Order) {
                continue;
            }

            $contractNumber = $order->contract?->number;

            if (blank($contractNumber)) {
                Log::warning('kapitalbank.reconcile.no_contract_number', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                ]);

                continue;
            }

            $candidates = [];

            foreach ($incoming as $rowIndex => $row) {
                $purpose = (string) ($row['purpose'] ?? '');
                $amount = (int) ($row['amount'] ?? -1);

                if (str_contains($purpose, $contractNumber) && $amount === (int) $payment->amount) {
                    $candidates[] = $rowIndex;
                    $rowClaims[$rowIndex][] = $payment->id;
                }
            }

            $paymentCandidates[$payment->id] = $candidates;
        }

        // Phase 2: confirm only the unambiguous 1-payment ↔ 1-row pairs.
        $consumedRows = [];

        foreach ($pending as $payment) {
            $candidates = $paymentCandidates[$payment->id] ?? [];

            if (count($candidates) === 0) {
                continue; // no match yet — retried on the next run
            }

            if (count($candidates) > 1) {
                Log::warning('kapitalbank.reconcile.payment_matches_multiple_rows', [
                    'payment_id' => $payment->id,
                    'candidate_rows' => count($candidates),
                ]);

                continue;
            }

            $rowIndex = $candidates[0];

            if (in_array($rowIndex, $consumedRows, true)) {
                // Already spent on another payment earlier in this pass.
                continue;
            }

            $claimants = $rowClaims[$rowIndex] ?? [];

            if (count(array_unique($claimants)) > 1) {
                Log::warning('kapitalbank.reconcile.row_matches_multiple_payments', [
                    'row_purpose' => $incoming[$rowIndex]['purpose'] ?? null,
                    'row_amount' => $incoming[$rowIndex]['amount'] ?? null,
                    'payment_ids' => array_values(array_unique($claimants)),
                ]);

                continue;
            }

            try {
                $this->payments->confirmBankTransferAutoMatch($payment, $incoming[$rowIndex]);
                $consumedRows[] = $rowIndex;

                Log::info('kapitalbank.reconcile.matched', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->payable_id,
                    'amount' => $payment->amount,
                ]);
            } catch (\Throwable $e) {
                Log::error('kapitalbank.reconcile.confirm_failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fetch the statement across the configured lookback window. A failure on
     * any one day is logged and skipped — a transient bank API hiccup must
     * not fail the whole scheduled run.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchStatementRows(): array
    {
        $lookbackDays = max(1, (int) config('kapitalbank.poll_lookback_days', 1));
        $rows = [];

        for ($daysAgo = 0; $daysAgo < $lookbackDays; $daysAgo++) {
            try {
                $rows = array_merge($rows, $this->client->getStatementForDaysAgo($daysAgo));
            } catch (\Throwable $e) {
                Log::error('kapitalbank.reconcile.statement_fetch_failed', [
                    'days_ago' => $daysAgo,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rows;
    }
}
