<?php

namespace App\Console\Commands;

use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayPayment;
use App\Services\Pass\GatewayPaymentService;
use Illuminate\Console\Command;

/**
 * ATMOS only pre-authorises the debit; the outcome is read from the invoice.
 * This sweep is the safety net when the mini app is closed before paying is
 * seen: it settles paid invoices and fails the ones that outlived their TTL.
 */
class ReconcileGatewayPayments extends Command
{
    protected $signature = 'gateway:reconcile-payments';

    protected $description = 'Settle or expire pending online-gateway payments';

    public function handle(GatewayPaymentService $payments): int
    {
        $ttl = (int) config('atmos.invoice_ttl');
        $settled = 0;
        $expired = 0;

        GatewayPayment::query()
            ->where('status', GatewayPaymentStatus::Pending)
            ->where('gateway', '!=', 'fake')
            ->where('created_at', '<=', now()->subMinute())
            ->orderBy('id')
            ->each(function (GatewayPayment $payment) use ($payments, $ttl, &$settled, &$expired): void {
                $payment = $payments->sync($payment);

                if ($payment->status->isFinal()) {
                    $settled++;

                    return;
                }

                if ($payment->created_at->lte(now()->subMinutes($ttl + 10))) {
                    $payments->expire($payment);
                    $expired++;
                }
            });

        $this->info("Settled {$settled}, expired {$expired}.");

        return self::SUCCESS;
    }
}
