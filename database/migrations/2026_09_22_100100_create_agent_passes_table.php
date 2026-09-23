<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_passes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('price_tiyin')->default(0);
            $table->string('source', 20); // gateway | wallet | admin
            $table->string('status', 20)->default('active');
            // One gateway payment activates at most one pass (idempotency).
            $table->foreignId('gateway_payment_id')->nullable()->unique()->constrained('gateway_payments')->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_passes');
    }
};
