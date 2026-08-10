<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 1: order-scoped negotiation threads on direct_chats.
 *
 * Marketplace DM = order_id IS NULL (one per client↔agent pair).
 * Order thread = order_id set (one per client↔agent↔order).
 *
 * Partial unique indexes are required: a plain unique(client_id, agent_id, order_id)
 * treats NULLs as distinct on both PostgreSQL and SQLite, which would allow
 * duplicate marketplace DMs. No backfill of existing pair threads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_chats', function (Blueprint $table): void {
            $table->foreignId('order_id')
                ->nullable()
                ->after('agent_profile_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->dropUnique(['client_id', 'agent_id']);
        });

        // Partial uniques — supported by PostgreSQL and SQLite (phpunit).
        DB::statement(
            'CREATE UNIQUE INDEX direct_chats_pair_unique ON direct_chats (client_id, agent_id) WHERE order_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX direct_chats_pair_order_unique ON direct_chats (client_id, agent_id, order_id) WHERE order_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS direct_chats_pair_unique');
        DB::statement('DROP INDEX IF EXISTS direct_chats_pair_order_unique');

        Schema::table('direct_chats', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
            $table->unique(['client_id', 'agent_id']);
        });
    }
};
