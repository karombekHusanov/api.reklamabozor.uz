<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicOrderDetailResource;
use App\Http\Resources\PublicOrderResource;
use App\Models\Order;
use App\Models\OrderView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOrderController extends ApiController
{
    /**
     * Public "live orders" feed for the home carousel. Shows the most recent
     * real orders (with their view / offer counters) as social proof that the
     * marketplace is active. `?limit` caps the result (default 10, max 20).
     */
    public function showcase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $limit = max(1, min($limit, 20));

        $orders = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->with(['category', 'client.avatarFile'])
            ->withCount(['views', 'offers'])
            ->latest()
            ->take($limit)
            ->get();

        return $this->success(PublicOrderResource::collection($orders));
    }

    /**
     * Showcase detail — full order view for authenticated users. Providers see
     * their own offer and whether they can bid. Records an OrderView when a
     * provider views someone else's order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->status === OrderStatus::Cancelled) {
            abort(404);
        }

        $user = $request->user();

        $order->loadMissing(['category', 'client.avatarFile']);
        $order->loadCount(['views', 'offers']);

        $order->load([
            'offers' => fn ($query) => $query->where('agent_id', $user->id),
        ]);

        Order::hydrateAttachmentFiles($order);

        if ($user->id !== $order->client_id) {
            $isProvider = $user->providerProfiles()->exists();
            if ($isProvider) {
                OrderView::upsert(
                    [['order_id' => $order->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]],
                    ['order_id', 'user_id'],
                    ['updated_at'],
                );
            }
        }

        return $this->success(new PublicOrderDetailResource($order));
    }
}
