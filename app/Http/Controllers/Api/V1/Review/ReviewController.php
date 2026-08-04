<?php

namespace App\Http\Controllers\Api\V1\Review;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Services\Rating\RatingCriteria;
use App\Services\Review\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        $review = $this->reviews->submitClientReview($request->user(), $order, $request->validated());

        return $this->success(new ReviewResource($review), 'Thank you for your feedback!', 201);
    }

    /**
     * Both reviews on this order (client→provider and provider→client).
     */
    public function index(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        $isClient = $order->client_id === $user->id;
        $isAgent = $order->acceptedOffer()->where('agent_id', $user->id)->exists();
        abort_unless($isClient || $isAgent, 404);

        $reviews = $order->reviews()
            ->with(['reviewer', 'reviewee'])
            ->get();

        return $this->success(ReviewResource::collection($reviews));
    }

    /**
     * Public list of rating criteria for a given role.
     */
    public function criteria(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['client', 'agent', 'designer', 'seller'])],
        ]);

        $role = Role::from($validated['role']);
        $criteria = RatingCriteria::forRole($role);

        return $this->success($criteria);
    }
}
