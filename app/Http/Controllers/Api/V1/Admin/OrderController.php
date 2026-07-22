<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexOrdersRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\AdminOrderResource;
use App\Http\Resources\ChatMessageResource;
use App\Models\Order;
use App\Services\Admin\OrderAdminService;
use Illuminate\Http\JsonResponse;

class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderAdminService $orders,
    ) {}

    public function index(IndexOrdersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->orders->list([
            'status' => $validated['status'] ?? null,
            'attention' => $validated['attention'] ?? null,
            'search' => $validated['search'] ?? null,
            'per_page' => $validated['per_page'] ?? 15,
        ]);

        return $this->success([
            'items' => AdminOrderResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return $this->success(new AdminOrderResource($this->orders->find($order)));
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $updated = $this->orders->updateStatus(
            $order,
            OrderStatus::from($request->validated()['status']),
        );

        return $this->success(new AdminOrderResource($updated), 'Order status updated');
    }

    /**
     * Read-only transcript of the order's client ↔ agent conversation, so the
     * ops team can review deals and resolve disputes.
     */
    public function chat(Order $order): JsonResponse
    {
        $chat = $order->chat()->with(['client', 'agent', 'agentProfile'])->first();

        if ($chat === null) {
            return $this->success(['chat' => null, 'messages' => []]);
        }

        $messages = $chat->messages()->with('attachments')->orderBy('id')->get();

        return $this->success([
            'chat' => [
                'id' => $chat->id,
                'client' => ['id' => $chat->client_id, 'name' => trim($chat->client->first_name.' '.($chat->client->last_name ?? ''))],
                'agent' => [
                    'id' => $chat->agent_id,
                    'name' => trim($chat->agent->first_name.' '.($chat->agent->last_name ?? '')),
                    'company_name' => $chat->agentProfile?->company_name,
                ],
            ],
            'messages' => ChatMessageResource::collection($messages),
        ]);
    }
}
