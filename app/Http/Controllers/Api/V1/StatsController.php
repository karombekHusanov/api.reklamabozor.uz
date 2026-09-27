<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Services\Realtime\LiveStatsService;
use Illuminate\Http\JsonResponse;

class StatsController extends ApiController
{
    /**
     * Public "live pulse" stats for the home screen. With realtime on the
     * mini app gets these pushed over WebSocket; this endpoint serves the
     * first paint (and the fallback when the socket is down).
     */
    public function live(LiveStatsService $stats): JsonResponse
    {
        return $this->success($stats->current());
    }
}
