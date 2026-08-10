<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_chats', function (Blueprint $table): void {
            $table->timestamp('blocked_at')->nullable()->after('agent_profile_id');
            $table->foreignId('blocked_by')
                ->nullable()
                ->after('blocked_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('direct_chats', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn('blocked_at');
        });
    }
};
