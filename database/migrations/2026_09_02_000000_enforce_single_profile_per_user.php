<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1 user = 1 profile (PROFILE_ARCHITECTURE.md): replace the multi-profile
 * `unique(user_id, provider_type)` with `unique(user_id)`, so the DB enforces
 * the invariant the app already assumes (creation gates block a second profile;
 * capability is derived from category type, not from separate profiles).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Data guard: never silently drop rows. If any user somehow holds more
        // than one profile, abort loudly so it is merged/resolved by hand first.
        $duplicates = DB::table('agent_profiles')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce unique(user_id) on agent_profiles — these users have multiple profiles: '
                .$duplicates->implode(', ').'. Merge/resolve them before running this migration.'
            );
        }

        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider_type']);
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->unique(['user_id', 'provider_type']);
        });
    }
};
