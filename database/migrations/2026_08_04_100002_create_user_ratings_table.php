<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->foreignId('agent_profile_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('stars', 3, 2)->default(5.00);
            $table->unsignedInteger('stars_count')->default(0);
            $table->unsignedSmallInteger('grade')->default(50);
            $table->smallInteger('listing_boost')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'role', 'agent_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ratings');
    }
};
