<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue of advertising formats sold per unit rather than per map point —
 * metro inventory first (Trans Group, 15 formats). A format has one price for
 * the whole network, a count per line, and may be a network-wide package.
 * Additive; rows come from AdFormatSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_formats', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('venue', 20)->default('metro'); // metro | …
            $table->string('medium', 10); // digital | static
            $table->string('name_uz', 150);
            $table->string('name_ru', 150);
            // size, resolution, material, hours …
            $table->json('specs')->nullable();
            $table->unsignedInteger('total_count')->default(1);
            // {"chilonzor": 59, "ozbekiston": 35, …}
            $table->json('line_counts')->nullable();
            // Sold only as the whole set (wagon monitors, wagon posters …).
            $table->boolean('is_package')->default(false);
            $table->unsignedSmallInteger('min_months')->default(1);
            // Printing + mounting are billed separately for static formats.
            $table->boolean('production_included')->default(true);
            $table->unsignedInteger('daily_reach')->nullable();
            // [{"slot_seconds": 5, "price_tiyin": 350000000}] per month, net of VAT;
            // slot_seconds 0 = static (no clip).
            $table->json('rates');
            $table->string('owner_name', 200);
            $table->json('owner_phones')->nullable();
            $table->string('owner_website', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['venue', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_formats');
    }
};
