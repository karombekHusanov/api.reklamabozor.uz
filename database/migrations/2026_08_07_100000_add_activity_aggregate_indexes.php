<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for GET /me/activity grouped aggregates.
 * Additive only — no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Client order buckets: WHERE client_id = ? GROUP BY status
            $table->index(['client_id', 'status'], 'orders_client_id_status_index');
            // Live-orders pulse: WHERE status IN (new, offers_sent) AND client_id != ?
            $table->index(['status', 'client_id'], 'orders_status_client_id_index');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->index(['agent_id', 'status'], 'offers_agent_id_status_index');
            $table->index(['agent_profile_id', 'status'], 'offers_agent_profile_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_client_id_status_index');
            $table->dropIndex('orders_status_client_id_index');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropIndex('offers_agent_id_status_index');
            $table->dropIndex('offers_agent_profile_id_status_index');
        });
    }
};
