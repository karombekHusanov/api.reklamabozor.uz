<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-managed catalog of provider advantages ("afzalliklarimiz").
        // Providers pick from this list — free text is intentionally not allowed,
        // so the marketplace stays consistent and needs no per-item moderation.
        Schema::create('advantages', function (Blueprint $table): void {
            $table->id();
            $table->string('name_uz', 80);
            $table->string('name_ru', 80);
            $table->string('hint_uz', 160)->nullable();
            $table->string('hint_ru', 160)->nullable();
            // Lucide icon key rendered by the clients (e.g. "timer", "shield-check").
            $table->string('icon', 40);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('agent_profile_advantage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advantage_id')->constrained()->cascadeOnDelete();

            $table->unique(['agent_profile_id', 'advantage_id']);
        });

        // Provider portfolio ("qilgan ishlarimiz"): auto-published, admin can
        // take an item down (hidden_at) without deleting the provider's data.
        Schema::create('agent_portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_file_id')->constrained('files');
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['agent_profile_id', 'sort_order']);
        });

        // Editable "ish jarayoni" steps: [{title, description?}, ...] (max 6).
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->jsonb('workflow_steps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn('workflow_steps');
        });
        Schema::dropIfExists('agent_portfolio_items');
        Schema::dropIfExists('agent_profile_advantage');
        Schema::dropIfExists('advantages');
    }
};
