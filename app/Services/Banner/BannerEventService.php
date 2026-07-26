<?php

namespace App\Services\Banner;

use App\Models\Banner;
use Illuminate\Support\Facades\DB;

class BannerEventService
{
    /**
     * Count one impression. High-volume, so we only bump the cached counter —
     * no per-row event log. Client dedupes per session to avoid inflation.
     */
    public function recordImpression(Banner $banner): void
    {
        $banner->increment('impressions_count');
    }

    /**
     * Count one click: append to the event log (for per-user reach and
     * time-series analysis) and bump the cached counter in one transaction.
     */
    public function recordClick(Banner $banner, ?int $userId): void
    {
        DB::transaction(function () use ($banner, $userId): void {
            $banner->clicks()->create([
                'user_id' => $userId,
            ]);

            $banner->increment('clicks_count');
        });
    }
}
