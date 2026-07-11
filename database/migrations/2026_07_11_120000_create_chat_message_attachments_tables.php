<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A message can now carry several files (sent together as one message).
        // The legacy single `file_id` columns stay in place (additive migration);
        // their data is copied into the pivots below and they are no longer written.
        Schema::create('chat_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['chat_message_id', 'file_id']);
        });

        Schema::create('global_chat_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('global_chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['global_chat_message_id', 'file_id']);
        });

        // Backfill: existing single-file messages become one-item attachment lists.
        DB::table('chat_message_attachments')->insertUsing(
            ['chat_message_id', 'file_id', 'position'],
            DB::table('chat_messages')->whereNotNull('file_id')->select('id', 'file_id', DB::raw('0')),
        );

        DB::table('global_chat_message_attachments')->insertUsing(
            ['global_chat_message_id', 'file_id', 'position'],
            DB::table('global_chat_messages')->whereNotNull('file_id')->select('id', 'file_id', DB::raw('0')),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('global_chat_message_attachments');
        Schema::dropIfExists('chat_message_attachments');
    }
};
