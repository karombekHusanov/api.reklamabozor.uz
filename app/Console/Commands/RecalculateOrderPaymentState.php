<?php

namespace App\Console\Commands;

use App\Enums\OrderPaymentState;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Re-derive `payment_state` from the payment ledger for every live order.
 *
 * Run once after deploying the ledger (and any time the money side looks off):
 * before it, the state was hand-set at each transition, so an order whose
 * amount changed later can carry a stale flag.
 */
class RecalculateOrderPaymentState extends Command
{
    protected $signature = 'orders:recalculate-payment-state {--dry-run : Report the drift without writing}';

    protected $description = 'Re-derive orders.payment_state from settled payments';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        Order::query()
            ->whereNotIn('payment_state', [OrderPaymentState::NotRequired, OrderPaymentState::Refunded])
            ->orderBy('id')
            ->chunkById(200, function ($orders) use ($dryRun, &$changed): void {
                foreach ($orders as $order) {
                    $before = $order->payment_state;
                    $expected = $order->outstandingTiyin() > 0
                        ? OrderPaymentState::Unpaid
                        : OrderPaymentState::Paid;

                    if ($before === $expected) {
                        continue;
                    }

                    $changed++;
                    $this->line(sprintf(
                        '#%d  %s → %s  (qoldiq: %s so\'m)',
                        $order->id,
                        $before->value,
                        $expected->value,
                        number_format($order->outstandingTiyin() / 100, 0, '.', ' '),
                    ));

                    if (! $dryRun) {
                        $order->recalculatePaymentState();
                    }
                }
            });

        $this->info($dryRun
            ? "{$changed} order(s) would change."
            : "{$changed} order(s) updated.");

        return self::SUCCESS;
    }
}
