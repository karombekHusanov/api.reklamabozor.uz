<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Services\Admin\OrdersByRouteReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin report: orders per client account split by route (Tender leakage view). */
class OrdersByRouteReportController extends ApiController
{
    public function __construct(private readonly OrdersByRouteReportService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tender_access' => ['nullable', 'in:granted'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success($this->service->report($validated));
    }
}
