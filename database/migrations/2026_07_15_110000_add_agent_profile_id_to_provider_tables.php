<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anchor provider-linked rows to the specific profile, not just the owning
 * user. `agent_id` (user) is kept for authz/ownership; `agent_profile_id` is
 * the attribution/reputation key so a user's separate provider profiles
 * (agent vs — in future — seller) keep independent ratings and history.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['offers', 'reviews', 'chats', 'direct_chats'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('agent_profile_id')
                    ->nullable()
                    ->after('agent_id')
                    ->constrained('agent_profiles')
                    ->nullOnDelete();
            });
        }

        // Backfill: today each user owns a single provider profile, so map every
        // row to its owning user's profile.
        DB::table('agent_profiles')->get(['id', 'user_id'])->each(function ($profile): void {
            foreach ($this->tables as $table) {
                DB::table($table)
                    ->where('agent_id', $profile->user_id)
                    ->update(['agent_profile_id' => $profile->id]);
            }
        });
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('agent_profile_id');
            });
        }
    }
};
