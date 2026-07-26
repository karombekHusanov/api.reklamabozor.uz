<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('banner_id')->constrained()->cascadeOnDelete();
            // Nullable — guests (no bearer token) can click banners too.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // Time-series queries and per-banner rollups.
            $table->index(['banner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_clicks');
    }
};
