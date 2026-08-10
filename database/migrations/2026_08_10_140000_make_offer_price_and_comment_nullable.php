<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 1 otklik: allow price-less, comment-less interest responses.
 * Existing priced offers keep their values; no backfill needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->decimal('price', 15, 2)->nullable()->change();
            $table->text('comment')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->decimal('price', 15, 2)->nullable(false)->change();
            $table->text('comment')->nullable(false)->change();
        });
    }
};
