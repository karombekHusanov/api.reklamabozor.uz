<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * Settle payments the callback left pending. Multicard fires a single callback
 * the moment a charge starts (status `progress`) and does NOT send a follow-up
 * when it settles to `success`, so a callback-only flow can strand an order in
 * `awaiting_payment`. This sweep re-queries the gateway (getPayment) for every
 * pending order payment and applies the authoritative status.
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile-pending';

    protected $description = 'Re-query Multicard for pending order payments and settle awaiting_payment orders';

    public function handle(PaymentService $payments): int
    {
        if (! config('services.multicard.enabled')) {
            return self::SUCCESS;
        }

        $pending = Payment::query()
            ->where('purpose', PaymentPurpose::Order)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->whereNotNull('gateway_uuid')
            // Ignore abandoned checkouts; a real charge settles within minutes.
            ->where('created_at', '>=', now()->subDay())
            ->whereHasMorph('payable', [Order::class], fn ($q) => $q->where('status', OrderStatus::AwaitingPayment))
            ->get();

        $settled = 0;

        foreach ($pending as $payment) {
            try {
                $payments->handleCallback(['uuid' => $payment->gateway_uuid]);

                if ($payment->fresh()?->status === PaymentStatus::Success) {
                    $settled++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Checked {$pending->count()} pending payment(s); {$settled} settled.");

        return self::SUCCESS;
    }
}
