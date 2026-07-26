<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            // Cached aggregate counters for fast list rendering. Impressions are
            // high-volume so they live only as a counter; clicks are also mirrored
            // in the banner_clicks event log for per-user / time-series analysis.
            $table->unsignedBigInteger('impressions_count')->default(0)->after('link_url');
            $table->unsignedBigInteger('clicks_count')->default(0)->after('impressions_count');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->dropColumn(['impressions_count', 'clicks_count']);
        });
    }
};
