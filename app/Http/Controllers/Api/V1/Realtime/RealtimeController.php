<?php

namespace App\Http\Controllers\Api\V1\Realtime;

use App\Http\Controllers\ApiController;
use App\Services\Realtime\LiveStatsService;
use App\Services\Realtime\RealtimeTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends ApiController
{
    /**
     * Centrifugo connection token for the current user. `enabled=false` →
     * the mini app stays on HTTP polling.
     */
    public function token(Request $request, RealtimeTokenIssuer $issuer): JsonResponse
    {
        if (! LiveStatsService::enabled()) {
            return $this->success(['enabled' => false]);
        }

        $issued = $issuer->issue($request->user());

        return $this->success([
            'enabled' => true,
            'ws_url' => config('realtime.ws_url'),
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ]);
    }
}
