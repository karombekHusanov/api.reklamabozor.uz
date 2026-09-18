<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\OrderNotifier;
use Illuminate\Console\Command;

/**
 * An order still open for offers (new / offers_sent) that received zero
 * offers after `orders.stale_order_reminder_days` gets a single one-time
 * nudge — no automatic re-broadcast or cancellation, that stays a manual
 * decision. An order with at least one offer (even a since-withdrawn one)
 * already got some engagement and is not "stale" in this sense.
 */
class RemindStaleOrders extends Command
{
    protected $signature = 'orders:remind-stale';

    protected $description = 'Remind clients (and ops) about orders that received zero offers for too long';

    public function handle(OrderNotifier $notifier): int
    {
        $days = max(1, (int) config('orders.stale_order_reminder_days', 3));

        $orders = Order::query()
            ->whereDoesntHave('offers')
            ->whereNull('stale_reminder_sent_at')
            ->where('created_at', '<=', now()->subDays($days))
            ->get()
            ->filter(fn (Order $order): bool => $order->status->isOpenForOffers());

        $reminded = 0;

        foreach ($orders as $order) {
            try {
                $notifier->notifyOrderStale($order);
                $order->update(['stale_reminder_sent_at' => now()]);
                $reminded++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Reminded {$reminded} / {$orders->count()} stale order(s).");

        return self::SUCCESS;
    }
}
