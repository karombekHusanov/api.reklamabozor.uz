<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\ApiController;
use App\Http\Resources\PayoutResource;
use App\Services\Payout\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent-facing earnings: the payouts owed to / paid to the signed-in provider,
 * plus a balance summary. Backs the "earnings + withdraw" area of the agent
 * profile in the mini app.
 */
class PayoutController extends ApiController
{
    public function __construct(
        private readonly PayoutService $payouts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $agent = $request->user();

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = $agent->payouts()
            ->with('order:id,title')
            ->latest()
            ->paginate($perPage);

        $balance = $this->payouts->balanceFor($agent);

        return $this->success([
            'balance' => [
                'available' => $balance['available'],
                'available_som' => $balance['available'] / 100,
                'processing' => $balance['processing'],
                'processing_som' => $balance['processing'] / 100,
                'paid' => $balance['paid'],
                'paid_som' => $balance['paid'] / 100,
                'total' => $balance['total'],
                'total_som' => $balance['total'] / 100,
                'currency' => 'UZS',
            ],
            'items' => PayoutResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
