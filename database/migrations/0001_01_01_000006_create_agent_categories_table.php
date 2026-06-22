<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_categories', function (Blueprint $table) {
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_custom')->default(false);

            $table->primary(['agent_profile_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_categories');
    }
};
