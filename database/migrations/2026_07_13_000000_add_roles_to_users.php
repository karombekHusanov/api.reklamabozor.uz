<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Every role the user holds (multirole). `role` stays the ACTIVE
            // role — all gating keys off it — while `roles` accumulates the
            // full set the user may switch between. Null = not backfilled yet;
            // the model normalizes it from `role`/`role_selected_at`.
            $table->json('roles')->nullable()->after('role');
        });

        // Backfill: a user with a committed role holds exactly that role.
        // Users still in onboarding (role_selected_at null, non-admin) hold none.
        foreach (Role::cases() as $role) {
            $query = DB::table('users')
                ->where('role', $role->value)
                ->whereNull('roles');

            if ($role !== Role::Admin) {
                $query->whereNotNull('role_selected_at');
            }

            $query->update(['roles' => json_encode([$role->value])]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('roles');
        });
    }
};
