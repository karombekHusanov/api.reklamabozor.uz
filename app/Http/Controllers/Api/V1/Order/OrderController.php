<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = $this->orders->listForClient($request->user());

        return $this->success(OrderResource::collection($orders));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->user(), $request->validated());

        return $this->success(
            new OrderResource($order),
            'Your request has been submitted. Our specialists will contact you.',
            201,
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->findForClient($request->user(), $order);

        return $this->success(new OrderResource($order));
    }
}
