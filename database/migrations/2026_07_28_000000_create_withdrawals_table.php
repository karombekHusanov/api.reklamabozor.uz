<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: this table is dropped by a later migration — the card cash-out flow it
// backed (hosted card form → gateway credit → OTP) was removed; the platform
// settled on manual bank transfers instead. Left as originally written.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method')->default('card');     // card (gateway credit) | bank (future)
            $table->unsignedBigInteger('amount');          // tiyin (som × 100)
            $table->string('currency', 3)->default('UZS');
            $table->string('status')->default('draft');    // WithdrawalStatus
            $table->string('session_id')->nullable();      // gateway bind-form session
            $table->text('form_url')->nullable();          // hosted card-entry page
            $table->string('card_token')->nullable();      // transient — annulled after use
            $table->string('card_pan')->nullable();        // masked, for display
            $table->string('ps')->nullable();              // uzcard | humo
            $table->string('gateway_uuid')->nullable();    // gateway credit uuid
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
