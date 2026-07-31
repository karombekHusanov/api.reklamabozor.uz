<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user last-seen cursor for the community chat badge.
        // Mirrors order/direct chat read tracking, but one row per user
        // (global feed has no per-message read_at for every reader).
        Schema::create('global_chat_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_seen_message_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_chat_reads');
    }
};
