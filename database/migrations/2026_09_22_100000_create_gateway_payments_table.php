<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20); // pass | topup
            $table->unsignedBigInteger('amount_tiyin');
            $table->string('status', 20)->default('pending'); // pending | success | failed
            $table->string('gateway', 30);
            $table->string('gateway_ref')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payments');
    }
};
