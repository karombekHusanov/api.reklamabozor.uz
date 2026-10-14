<?php

namespace App\Console\Commands;

use App\Services\Order\TezkorPickService;
use Illuminate\Console\Command;

/** Tezkor pay_on_pick: clear picks whose payment window ran out, so the client can pick again. */
class ExpireTezkorPicks extends Command
{
    protected $signature = 'tezkor:expire-picks';

    protected $description = 'Clear Tezkor picks the agency did not pay for in time';

    public function handle(TezkorPickService $picks): int
    {
        $count = $picks->expireLapsed();

        $this->info("Lapsed picks cleared: {$count}");

        return self::SUCCESS;
    }
}
