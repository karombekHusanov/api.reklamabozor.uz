<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only: the `seller` role never had a flow and was removed from the
 * Role enum (audit 2026-10-16 §3.1). Prod held no seller at the time; this
 * is a safety net so a stray row can't break the enum casts:
 *  - users.role seller → client (the universal base role);
 *  - users.roles json: the `seller` entry is dropped;
 *  - user_ratings / global_chat_rules rows keyed by the seller role are removed
 *    (stub rating rows and cooldown rules for a role nobody can hold).
 * No schema change; the `users.role` DB enum (where present) keeps the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('users')->where('role', 'seller')->update(['role' => 'client']);

            DB::table('users')
                ->whereNotNull('roles')
                ->where('roles', 'like', '%seller%')
                ->select(['id', 'roles'])
                ->orderBy('id')
                ->each(function (object $user): void {
                    $roles = (array) json_decode((string) $user->roles, true);
                    $kept = array_values(array_filter($roles, fn (mixed $role): bool => $role !== 'seller'));

                    if ($kept === $roles) {
                        return;
                    }

                    if (! in_array('client', $kept, true)) {
                        $kept[] = 'client';
                    }

                    DB::table('users')->where('id', $user->id)->update(['roles' => json_encode($kept)]);
                });

            DB::table('user_ratings')->where('role', 'seller')->delete();
            DB::table('global_chat_rules')->where('role', 'seller')->delete();
        });
    }

    public function down(): void
    {
        // The removed role cannot be restored; intentionally a no-op.
    }
};
