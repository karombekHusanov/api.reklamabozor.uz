<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "last opened Live Orders list" cursor — drives the home badge
 * (orders newer than last_seen_at). Mirrors global_chat_reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_order_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_order_reads');
    }
};
