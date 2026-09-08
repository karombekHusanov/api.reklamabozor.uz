<?php

namespace App\Console\Commands;

use App\Enums\AmendmentStatus;
use App\Models\OrderAmendment;
use App\Services\Order\AmendmentService;
use Illuminate\Console\Command;

/**
 * Keeps proposed additional agreements moving: nudges the party that has not
 * answered as the deadline approaches, and closes the ones nobody answered.
 * A stale proposal otherwise blocks the order from getting a new one.
 */
class SweepAmendments extends Command
{
    protected $signature = 'amendments:sweep';

    protected $description = 'Remind about expiring additional agreements and expire the stale ones';

    public function handle(AmendmentService $amendments): int
    {
        $reminded = 0;
        $expired = 0;

        // Deadline within the next 24h and nobody nudged yet.
        OrderAmendment::query()
            ->where('status', AmendmentStatus::Pending)
            ->whereNotNull('expires_at')
            ->whereNull('reminder_sent_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDay())
            ->get()
            ->each(function (OrderAmendment $amendment) use ($amendments, &$reminded): void {
                try {
                    $amendments->remind($amendment);
                    $reminded++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        OrderAmendment::query()
            ->where('status', AmendmentStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (OrderAmendment $amendment) use ($amendments, &$expired): void {
                try {
                    $amendments->expire($amendment);
                    $expired++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        $this->info("Reminded {$reminded}, expired {$expired} amendment(s).");

        return self::SUCCESS;
    }
}
