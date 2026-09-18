<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two "remind once" gates, each paired with an existing sweep/command:
 *  - `correction_reminder_sent_at` — one nudge to the agent ~24h before a
 *    quality dispute's correction window runs out (`orders:sweep-quality-disputes`).
 *  - `stale_reminder_sent_at` — one nudge to the client when an order sat
 *    open-for-offers with zero offers for too long (`orders:remind-stale`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('correction_reminder_sent_at')->nullable()->after('correction_deadline_at');
            $table->timestamp('stale_reminder_sent_at')->nullable()->after('problem_resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['correction_reminder_sent_at', 'stale_reminder_sent_at']);
        });
    }
};
