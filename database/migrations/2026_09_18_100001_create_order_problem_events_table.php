<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for a problem order: flagged / refunded / dismissed, with who
 * did it (null actor = a scheduled sweep) and when. Mirrors amendment_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_problem_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // client | agent | admin | system
            $table->string('actor_role', 16);
            $table->string('type', 32)->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_problem_events');
    }
};
