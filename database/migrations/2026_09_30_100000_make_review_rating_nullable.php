<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Comment-only reviews carry no star score, so `rating` must allow null. */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->decimal('rating', 3, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Additive-only in prod: nullable stays.
    }
};
