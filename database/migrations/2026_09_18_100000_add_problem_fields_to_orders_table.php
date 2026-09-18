<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Problem orders" queue: a quality dispute whose correction window ran out
 * without the order reaching `completed`, or an agent who took the advance
 * but never started the work — both funnel here for a manager to resolve
 * (manual refund or dismiss). Tracked orthogonally to `status`, the same way
 * `payment_state` runs on its own track.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('problem_state', 16)->default('none')->after('disputed_at');
            $table->string('problem_reason', 32)->nullable()->after('problem_state');
            $table->timestamp('problem_flagged_at')->nullable()->after('problem_reason');
            // Quality dispute: order must reach `completed` before this, or the
            // sweep flags it. Set (and refreshed) by OrderService::disputeCompletion.
            $table->timestamp('correction_deadline_at')->nullable()->after('problem_flagged_at');
            $table->timestamp('problem_resolved_at')->nullable()->after('correction_deadline_at');

            $table->index('problem_state');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'problem_state',
                'problem_reason',
                'problem_flagged_at',
                'correction_deadline_at',
                'problem_resolved_at',
            ]);
        });
    }
};
