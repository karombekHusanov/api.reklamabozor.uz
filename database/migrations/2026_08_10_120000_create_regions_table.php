<?php

use Database\Seeders\RegionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('regions')
                ->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name_uz', 100);
            $table->string('name_ru', 100);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        (new RegionSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
