<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\StoreOfferRequest;
use App\Http\Requests\Api\V1\Review\StoreReviewRequest;
use App\Http\Resources\AgentOfferResource;
use App\Http\Resources\AgentOrderResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Services\Order\OfferService;
use App\Services\Order\OrderService;
use App\Services\Review\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentOrderController extends ApiController
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly OrderService $orders,
        private readonly ReviewService $reviews,
    ) {}

    /**
     * Orders the agent can bid on (open, in their categories).
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $this->offers->availableForAgent($request->user());

        return $this->success(AgentOrderResource::collection($orders));
    }

    /**
     * The agent's own offers across all orders.
     */
    public function myOffers(Request $request): JsonResponse
    {
        $offers = $this->offers->listForAgent($request->user());

        return $this->success(AgentOfferResource::collection($offers));
    }

    /**
     * Submit an offer for an order.
     */
    public function storeOffer(StoreOfferRequest $request, Order $order): JsonResponse
    {
        $offer = $this->offers->submitOffer($request->user(), $order, $request->validated());

        return $this->success(new OfferResource($offer), 'Offer submitted', 201);
    }

    /**
     * The winning agent marks the work as delivered (awaits client confirmation).
     */
    public function submitWork(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->submitWork($request->user(), $order);

        return $this->success(new OrderResource($order), 'Work submitted — waiting for the client to confirm.');
    }

    /**
     * Provider rates the client on their completed order.
     */
    public function storeReview(StoreReviewRequest $request, Order $order): JsonResponse
    {
        $review = $this->reviews->submitProviderReview($request->user(), $order, $request->validated());

        return $this->success(new ReviewResource($review), 'Thank you for your feedback!', 201);
    }
}
