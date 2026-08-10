<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional administrative region / district on orders.
 * Null region_id = all Uzbekistan; map pin (lat/lng) stays separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('region_id')
                ->nullable()
                ->after('location_label')
                ->constrained('regions')
                ->restrictOnDelete();
            $table->foreignId('district_id')
                ->nullable()
                ->after('region_id')
                ->constrained('regions')
                ->restrictOnDelete();
            $table->index('region_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('region_id');
        });
    }
};
