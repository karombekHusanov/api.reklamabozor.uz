<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client location on orders — where the work / client is based.
 * Same shape as agent_profiles (lat/lng/location_label).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('budget_max');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->string('location_label', 200)->nullable()->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng', 'location_label']);
        });
    }
};
