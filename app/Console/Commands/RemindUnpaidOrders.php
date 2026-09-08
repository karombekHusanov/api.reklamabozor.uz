<?php

namespace App\Console\Commands;

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\OrderNotifier;
use Illuminate\Console\Command;

/**
 * Nudge clients whose deal is already running but whose payment is past due.
 * Deliberately does NOT cancel the order — the agent may already be working;
 * ops decides what to do with a stubborn debtor.
 */
class RemindUnpaidOrders extends Command
{
    protected $signature = 'orders:remind-unpaid';

    protected $description = 'Remind clients (and ops) about active orders whose payment is overdue';

    public function handle(OrderNotifier $notifier): int
    {
        $orders = Order::query()
            ->where('payment_state', OrderPaymentState::Unpaid)
            ->whereIn('status', [OrderStatus::InProgress, OrderStatus::WorkSubmitted])
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())
            // At most one nudge per day per order.
            ->where(fn ($q) => $q->whereNull('payment_reminded_at')
                ->orWhere('payment_reminded_at', '<=', now()->subDay()))
            ->get();

        $reminded = 0;

        foreach ($orders as $order) {
            try {
                $notifier->notifyPaymentOverdue($order);
                $order->update(['payment_reminded_at' => now()]);
                $reminded++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Reminded {$reminded} / {$orders->count()} overdue order(s).");

        return self::SUCCESS;
    }
}
