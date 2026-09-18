<?php

namespace App\Console\Commands;

use App\Services\Payment\BankReconciliationService;
use Illuminate\Console\Command;

/**
 * Poll Kapitalbank's statement (GetDoc1C) and auto-confirm bank-transfer
 * payments whose contract number + exact amount match an incoming transfer.
 * Safe to schedule unconditionally — a no-op until KAPITALBANK_ENABLED=true.
 */
class ReconcileBankPayments extends Command
{
    protected $signature = 'orders:reconcile-bank-payments';

    protected $description = 'Auto-match incoming Kapitalbank transfers to pending bank-transfer payments';

    public function handle(BankReconciliationService $service): int
    {
        if (! config('kapitalbank.enabled')) {
            $this->info('Kapitalbank integration disabled — skipping.');

            return self::SUCCESS;
        }

        $service->reconcile();

        return self::SUCCESS;
    }
}
