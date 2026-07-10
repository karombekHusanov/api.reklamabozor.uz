<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Services\Admin\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends ApiController
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * Dashboard analytics: ops queue, KPIs, trends, funnel, liquidity,
     * category and agent performance for the selected period.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d'])],
        ]);

        $days = (int) rtrim($validated['period'] ?? '30d', 'd');

        return $this->success($this->analytics->dashboard($days));
    }

    /**
     * Latest marketplace events for the dashboard activity feed.
     */
    public function activity(): JsonResponse
    {
        return $this->success($this->analytics->activity());
    }
}
