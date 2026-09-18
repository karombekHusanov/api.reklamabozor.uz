<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a manager closed a problem order: a manual refund (amount judged by a
 * human, often partial — the money itself moves outside the platform, this
 * is only the audit entry, the same way PayoutService::release records a
 * bank transfer it did not send) or a dismissal back to the normal deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_problem_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // refunded | dismissed
            $table->string('resolution', 16);
            // Tiyin. Nullable — a dismissal records no money.
            $table->integer('refund_amount')->nullable();
            $table->string('refund_method', 16)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('resolved_by')->constrained('users');
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_problem_resolutions');
    }
};
