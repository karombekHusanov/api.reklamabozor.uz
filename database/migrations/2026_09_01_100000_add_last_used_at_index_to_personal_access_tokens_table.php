<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up the "online users/agents" query behind GET /stats/live, which filters
 * personal_access_tokens by tokenable_type + a recent last_used_at window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->index(['tokenable_type', 'last_used_at'], 'pat_type_last_used_idx');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('pat_type_last_used_idx');
        });
    }
};
