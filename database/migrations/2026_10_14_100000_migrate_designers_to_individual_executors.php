<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `designer` role/track is retired: providers ("ijrochi") are one role —
 * `agent` — in two legal kinds, legal entity (`provider_type=agent`) and
 * individual (`provider_type=individual`). Existing designers were individual
 * providers, so they become individual executors under the agent role.
 *
 * Data-only and forward-only: no columns change, nothing is dropped. The
 * `users.role` enum keeps `designer` as an allowed value, it just goes unused.
 * Designer *category* types (`categories.type=designer`) are a marketplace
 * capability, not a role — untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('agent_profiles')
                ->where('provider_type', 'designer')
                ->update(['provider_type' => 'individual']);

            DB::table('users')
                ->where('role', 'designer')
                ->update(['role' => 'agent']);

            // Held capability set (json): designer → agent, deduplicated. Filtered
            // in PHP — Postgres `json` has no LIKE / equality operators.
            DB::table('users')
                ->whereNotNull('roles')
                ->select(['id', 'roles'])
                ->orderBy('id')
                ->each(function (object $user): void {
                    $roles = (array) json_decode((string) $user->roles, true);
                    if (! in_array('designer', $roles, true)) {
                        return;
                    }

                    $roles = array_values(array_unique(array_map(
                        fn (string $role): string => $role === 'designer' ? 'agent' : $role,
                        $roles,
                    )));

                    DB::table('users')->where('id', $user->id)->update(['roles' => json_encode($roles)]);
                });

            // Rating cache: re-key designer rows to agent unless an agent row
            // for the same (user, profile) already exists — then the designer
            // row is redundant (ratings:recompute-all rebuilds either way).
            DB::table('user_ratings')
                ->where('role', 'designer')
                ->orderBy('id')
                ->get()
                ->each(function (object $row): void {
                    $agentRowExists = DB::table('user_ratings')
                        ->where('user_id', $row->user_id)
                        ->where('role', 'agent')
                        ->where(fn ($q) => $row->agent_profile_id === null
                            ? $q->whereNull('agent_profile_id')
                            : $q->where('agent_profile_id', $row->agent_profile_id))
                        ->exists();

                    $agentRowExists
                        ? DB::table('user_ratings')->where('id', $row->id)->delete()
                        : DB::table('user_ratings')->where('id', $row->id)->update(['role' => 'agent']);
                });

            // Global chat per-role write rule: the agent rule now covers them.
            DB::table('global_chat_rules')->where('role', 'designer')->delete();
        });
    }

    public function down(): void
    {
        // Irreversible: which agents were designers is not recorded.
    }
};
