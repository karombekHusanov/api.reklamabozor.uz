<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_chat_messages', function (Blueprint $table): void {
            $table->string('type', 32)->default('text')->after('sender_id');
            $table->json('meta')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('direct_chat_messages', function (Blueprint $table): void {
            $table->dropColumn(['type', 'meta']);
        });
    }
};
