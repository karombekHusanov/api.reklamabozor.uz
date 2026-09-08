<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pricelist row carries its own fiscal data so the receipt can be rebuilt
 * later even if the catalogue entry changes: MXIK + packaging + VAT are frozen
 * on the row when the pricelist is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->string('package_code', 32)->nullable()->after('mxik_code');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->dropColumn('package_code');
        });
    }
};
