<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderNotifier;
use App\Services\Order\OrderService;
use Illuminate\Console\Command;

/**
 * Hourly sweep over orders awaiting completion confirmation:
 * day 2 — remind the client; day 3 — auto-complete silently ignored orders.
 */
class ProcessOrderCompletions extends Command
{
    protected $signature = 'orders:process-completions';

    protected $description = 'Send day-2 confirmation reminders and auto-complete work_submitted orders after 3 days';

    public function handle(OrderService $orders, OrderNotifier $notifier): int
    {
        $reminded = 0;
        $completed = 0;

        // Day 3+: the client stayed silent — accept the work automatically.
        Order::query()
            ->byStatus(OrderStatus::WorkSubmitted)
            ->where('work_submitted_at', '<=', now()->subDays(3))
            ->each(function (Order $order) use ($orders, &$completed): void {
                $orders->complete($order, auto: true);
                $completed++;
            });

        // Day 2: one nudge before the auto-complete kicks in.
        Order::query()
            ->byStatus(OrderStatus::WorkSubmitted)
            ->where('work_submitted_at', '<=', now()->subDays(2))
            ->whereNull('completion_reminder_sent_at')
            ->each(function (Order $order) use ($notifier, &$reminded): void {
                $order->update(['completion_reminder_sent_at' => now()]);

                try {
                    $notifier->notifyCompletionReminder($order);
                } catch (\Throwable $e) {
                    report($e);
                }

                $reminded++;
            });

        $this->info("Reminded: {$reminded}, auto-completed: {$completed}.");

        return self::SUCCESS;
    }
}
