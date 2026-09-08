<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Console\Command;

/**
 * Agent earnings leave the platform as a bank transfer a manager executes by
 * hand — the gateway has no account-payout API. This sweep announces payouts
 * whose cooling-off window has closed, once each, so nothing sits in the queue
 * waiting to be noticed.
 */
class NotifyDuePayouts extends Command
{
    protected $signature = 'payouts:notify-due';

    protected $description = 'Tell ops which agent payouts are ready for their bank transfer';

    public function handle(AdminNotifier $notifier): int
    {
        $due = Payout::query()
            ->releasable()
            ->whereNull('notified_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No payouts are waiting for a transfer.');

            return self::SUCCESS;
        }

        $total = (int) $due->sum('amount');

        $notifier->payoutsDue($due->count(), $total);

        Payout::query()->whereIn('id', $due->pluck('id'))->update(['notified_at' => now()]);

        $this->info("Announced {$due->count()} payout(s), {$total} tiyin.");

        return self::SUCCESS;
    }
}
