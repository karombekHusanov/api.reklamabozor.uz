<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Set once when the user picks their role during onboarding; afterwards
            // only an admin may change the role. Null = role not yet self-selected.
            $table->timestamp('role_selected_at')->nullable()->after('role');
        });

        // Existing non-client users already have a deliberate role — mark it as selected
        // so they are not pushed back through onboarding's role step.
        DB::table('users')
            ->where('role', '!=', 'client')
            ->whereNull('role_selected_at')
            ->update(['role_selected_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role_selected_at');
        });
    }
};
