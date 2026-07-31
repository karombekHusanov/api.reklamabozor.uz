<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * Cancel orders that stayed in awaiting_payment past the configured timeout
 * (default 72h). Best-effort cancels open Multicard invoices first.
 */
class CancelExpiredAwaitingPayments extends Command
{
    protected $signature = 'orders:cancel-expired-awaiting-payments';

    protected $description = 'Auto-cancel orders whose awaiting_payment window has elapsed';

    public function handle(PaymentService $payments): int
    {
        if (! config('services.multicard.enabled')) {
            return self::SUCCESS;
        }

        $hours = max(1, (int) config('services.multicard.awaiting_payment_timeout_hours', 72));
        $cutoff = now()->subHours($hours);

        $orders = Order::query()
            ->where('status', OrderStatus::AwaitingPayment)
            ->whereNotNull('awaiting_payment_at')
            ->where('awaiting_payment_at', '<=', $cutoff)
            ->get();

        $cancelled = 0;

        foreach ($orders as $order) {
            try {
                $payments->expireAwaitingPayment($order);
                $cancelled++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Cancelled {$cancelled} / {$orders->count()} expired awaiting_payment order(s) (timeout {$hours}h).");

        return self::SUCCESS;
    }
}
