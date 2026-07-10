<?php

namespace App\Http\Controllers\Api\V1\Review;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Services\Review\ReviewService;
use Illuminate\Http\JsonResponse;

class ReviewController extends ApiController
{
    public function __construct(
        private readonly ReviewService $reviews,
    ) {}

    /**
     * Client rates the winning agency on their completed order.
     */
    public function store(StoreReviewRequest $request, Order $order): JsonResponse
    {
        $review = $this->reviews->submit($request->user(), $order, $request->validated());

        return $this->success(new ReviewResource($review), 'Thank you for your feedback!', 201);
    }
}
