<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->nullable();
            $table->string('subtitle', 160)->nullable();
            // Banner artwork shown in the mini app home slider.
            $table->foreignId('image_file_id')->nullable()->constrained('files')->nullOnDelete();
            // Optional click target (deep-link or external URL).
            $table->string('link_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
