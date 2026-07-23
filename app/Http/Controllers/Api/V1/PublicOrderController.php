<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicOrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOrderController extends ApiController
{
    /**
     * Public "live orders" feed for the home carousel. Shows the most recent
     * real orders (with their view / offer counters) as social proof that the
     * marketplace is active. Anonymised — see PublicOrderResource. `?limit`
     * caps the result (default 10, max 20).
     */
    public function showcase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $limit = max(1, min($limit, 20));

        $orders = Order::query()
            // Cancelled orders don't reflect healthy activity — keep them out.
            ->where('status', '!=', OrderStatus::Cancelled)
            ->with('category')
            ->withCount(['views', 'offers'])
            ->latest()
            ->take($limit)
            ->get();

        return $this->success(PublicOrderResource::collection($orders));
    }
}
