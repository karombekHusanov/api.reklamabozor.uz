<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The exclusive Tezkor claim is retired: any paying agent may respond and the
 * client picks the agency. `claimed_agent_id` now means "the agency the client
 * picked" and is only set on a closed request — so clear it on requests that
 * are still open. The claiming agent's otklik stays pending, like any other.
 * Data-only, nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('route', 'tezkor')
            ->whereIn('status', ['new', 'offers_sent'])
            ->whereNotNull('claimed_agent_id')
            ->update(['claimed_agent_id' => null, 'claimed_at' => null]);
    }

    public function down(): void
    {
        // Not reversible — the claims were released on purpose.
    }
};
