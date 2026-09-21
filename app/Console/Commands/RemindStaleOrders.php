<?php

namespace App\Console\Commands;

use App\Enums\OrderRoute;
use App\Models\Order;
use App\Services\Order\OrderNotifier;
use Illuminate\Console\Command;

/**
 * An order still open for offers (new / offers_sent) that received zero
 * offers after `orders.stale_order_reminder_days` gets a single one-time
 * nudge — no automatic re-broadcast or cancellation, that stays a manual
 * decision. Claimed Tezkor requests get their own nudge (see below). An order with at least one offer (even a since-withdrawn one)
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

        // Claimed Tezkor requests: nobody auto-releases them, so the client gets
        // one nudge (close as agreed, or reopen) once the claim has sat too long.
        $claimed = Order::query()
            ->where('route', OrderRoute::Tezkor->value)
            ->whereNotNull('claimed_agent_id')
            ->whereNull('stale_reminder_sent_at')
            ->where('claimed_at', '<=', now()->subDays($days))
            ->get()
            ->filter(fn (Order $order): bool => $order->status->isOpenForOffers());

        $claimedReminded = 0;

        foreach ($claimed as $order) {
            try {
                $notifier->notifyClaimStale($order);
                $order->update(['stale_reminder_sent_at' => now()]);
                $claimedReminded++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $reminded += $claimedReminded;
        $total = $orders->count() + $claimed->count();

        $this->info("Reminded {$reminded} / {$total} stale order(s).");

        return self::SUCCESS;
    }
}
