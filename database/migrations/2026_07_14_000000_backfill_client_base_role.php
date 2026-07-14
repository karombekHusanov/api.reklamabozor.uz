<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client is now the universal base role: every non-admin user holds it and
     * the onboarding role is added on top instead of replacing it. Backfill the
     * client role into every existing non-admin user's held set. Additive and
     * idempotent — safe to run with `migrate --force`.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('role', '!=', Role::Admin->value)
            ->orderBy('id')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $roles = $user->roles ? (array) json_decode($user->roles, true) : [];

                    if (! in_array(Role::Client->value, $roles, true)) {
                        $roles[] = Role::Client->value;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['roles' => json_encode(array_values(array_unique($roles)))]);
                }
            });
    }

    /**
     * Data-only backfill; there is no reliable way to tell an originally-held
     * client role from a backfilled one, so this is intentionally irreversible.
     */
    public function down(): void
    {
        // no-op
    }
};
