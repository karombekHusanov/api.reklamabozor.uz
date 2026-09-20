<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payouts owed to agents out of an order's escrow. The client pays 100% up
 * front (payments table); the platform then releases the money to the agent in
 * tranches — advance on deal start, final on completion — minus commission.
 * v1 releases are manual (a manager marks them paid); `gateway_uuid` was added
 * for a future automated card-credit flow (a later migration drops it — the
 * platform settled on manual bank transfers instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Recipient provider profile (attribution) + user (the payee).
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('tranche');                    // PayoutTranche
            $table->unsignedBigInteger('amount');         // tiyin (som × 100)
            $table->string('currency', 3)->default('UZS');
            $table->string('status')->default('pending'); // PayoutStatus
            $table->string('method')->nullable();         // manual | bank | card
            $table->string('gateway_uuid')->nullable();   // future automated credit flow uuid
            $table->string('reference')->nullable();      // manual transfer reference
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'tranche']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
