<?php

namespace App\Console\Commands;

use App\Enums\OrderProblemState;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderNotifier;
use App\Services\Order\OrderProblemService;
use Illuminate\Console\Command;

/**
 * A quality dispute (OrderService::disputeCompletion) gives the deal a
 * correction window to reach `completed`. Two things happen on this one daily
 * pass, same as `amendments:sweep`:
 *  - a day before the deadline, the agent gets a one-time nudge;
 *  - an order that runs out the clock without reaching `completed` becomes a
 *    problem order for a manager to resolve.
 */
class SweepQualityDisputes extends Command
{
    protected $signature = 'orders:sweep-quality-disputes';

    protected $description = 'Remind agents whose correction window is closing, and flag the ones that ran out';

    public function handle(OrderProblemService $problems, OrderNotifier $notifier): int
    {
        $reminded = $this->remindApproaching($notifier);
        $flagged = $this->flagOverdue($problems);

        $this->info("Reminded {$reminded}, flagged {$flagged} order(s) with a quality dispute.");

        return self::SUCCESS;
    }

    private function remindApproaching(OrderNotifier $notifier): int
    {
        $reminded = 0;

        Order::query()
            ->where('problem_state', OrderProblemState::None)
            ->where('status', '!=', OrderStatus::Completed)
            ->whereNotNull('correction_deadline_at')
            ->whereNull('correction_reminder_sent_at')
            ->whereBetween('correction_deadline_at', [now(), now()->addDay()])
            ->get()
            ->each(function (Order $order) use ($notifier, &$reminded): void {
                try {
                    $notifier->notifyCorrectionDeadlineApproaching($order);
                    $order->update(['correction_reminder_sent_at' => now()]);
                    $reminded++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $reminded;
    }

    private function flagOverdue(OrderProblemService $problems): int
    {
        $flagged = 0;

        Order::query()
            ->where('problem_state', OrderProblemState::None)
            ->where('status', '!=', OrderStatus::Completed)
            ->whereNotNull('correction_deadline_at')
            ->where('correction_deadline_at', '<=', now())
            ->get()
            ->each(function (Order $order) use ($problems, &$flagged): void {
                try {
                    $problems->flagFromDispute($order);
                    $flagged++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $flagged;
    }
}
