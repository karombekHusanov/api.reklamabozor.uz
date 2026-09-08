<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for an additional agreement: every decision and money movement,
 * with who did it and from where. This is what the admin panel's timeline
 * renders — a dispute has to be answerable from this table alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('amendment_id')->constrained('order_amendments')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // client | agent | operator | system
            $table->string('actor_role', 16);
            $table->string('type', 32)->index();
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['amendment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendment_events');
    }
};
