<?php

namespace App\Console\Commands;

use App\Services\Payment\KapitalBankPayoutQueueService;
use Illuminate\Console\Command;

/**
 * Push releasable agent payouts to Kapitalbank as unsigned SendPaymentIBK
 * orders (input only — signing/sending stays manual on the bank's website).
 * No-op until both KAPITALBANK_ENABLED and PAYOUT_BANK_QUEUE_ENABLED are true.
 */
class QueueBankPayouts extends Command
{
    protected $signature = 'payouts:queue-bank-transfers';

    protected $description = 'Queue releasable agent payouts as unsigned payment orders at Kapitalbank';

    public function handle(KapitalBankPayoutQueueService $service): int
    {
        if (! config('kapitalbank.enabled') || ! config('payouts.bank_queue_enabled')) {
            $this->info('Kapitalbank payout queueing disabled — skipping.');

            return self::SUCCESS;
        }

        $service->queuePending();

        return self::SUCCESS;
    }
}
