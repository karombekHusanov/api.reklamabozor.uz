<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Community-wide chat, open to every authenticated marketplace user.
        Schema::create('global_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            // Soft-moderation: deleted messages disappear from the app but stay
            // in the DB for audit (who removed what, when).
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['deleted_at', 'id']);
            $table->index(['user_id', 'created_at']);
        });

        // Write bans: expires_at null = permanent. Unban = delete the row.
        Schema::create('global_chat_bans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banned_by')->constrained('users');
            $table->string('reason', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        // Write-frequency rules: exactly one of role / user_id is set.
        // A user override always wins over the user's role rule.
        Schema::create('global_chat_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 20)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cooldown_seconds');
            $table->timestamps();
        });

        // Single-row global settings (id = 1).
        Schema::create('global_chat_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('max_message_length')->default(500);
            $table->text('pinned_message')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_chat_settings');
        Schema::dropIfExists('global_chat_rules');
        Schema::dropIfExists('global_chat_bans');
        Schema::dropIfExists('global_chat_messages');
    }
};
