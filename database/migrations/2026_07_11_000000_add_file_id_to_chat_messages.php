<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional file attachment on a message (image or document). The body
        // stays required at the DB level (empty string for file-only messages),
        // so this addition is fully additive.
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('file_id')->nullable()->after('body')
                ->constrained('files')->nullOnDelete();
        });

        Schema::table('global_chat_messages', function (Blueprint $table): void {
            $table->foreignId('file_id')->nullable()->after('body')
                ->constrained('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('file_id');
        });

        Schema::table('global_chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('file_id');
        });
    }
};
