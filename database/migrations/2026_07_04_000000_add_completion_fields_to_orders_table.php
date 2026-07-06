<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Completion handshake: agent submits work → client confirms
            // (or the scheduler auto-completes after 3 silent days).
            $table->timestamp('work_submitted_at')->nullable();
            $table->timestamp('completion_reminder_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('auto_completed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'work_submitted_at',
                'completion_reminder_sent_at',
                'completed_at',
                'auto_completed',
            ]);
        });
    }
};
