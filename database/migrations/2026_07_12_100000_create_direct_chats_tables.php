<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-deal client ↔ agency conversation, opened from an agent profile.
        Schema::create('direct_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('agent_id')->constrained('users');
            $table->timestamps();

            $table->unique(['client_id', 'agent_id']);
        });

        Schema::create('direct_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direct_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['direct_chat_id', 'id']);
        });

        Schema::create('direct_chat_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('direct_chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['direct_chat_message_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_chat_message_attachments');
        Schema::dropIfExists('direct_chat_messages');
        Schema::dropIfExists('direct_chats');
    }
};
