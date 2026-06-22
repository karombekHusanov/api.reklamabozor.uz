<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexOrdersRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\AdminOrderResource;
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
}
