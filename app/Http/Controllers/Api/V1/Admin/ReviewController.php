<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\AdminReviewResource;
use App\Models\Review;
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
     * Moderation queue — filterable by status and direction, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'direction' => ['nullable', Rule::enum(ReviewDirection::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Review::query()
            ->with(['client', 'agent', 'agentProfile', 'reviewer', 'reviewee'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['direction'] ?? null, fn ($q, $dir) => $q->where('direction', $dir))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return $this->success([
            'items' => AdminReviewResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Approve or reject a review. Approving triggers a rating recompute.
     */
    public function updateStatus(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([ReviewStatus::Approved->value, ReviewStatus::Rejected->value])],
        ]);

        $status = ReviewStatus::from($validated['status']);
        $review = $this->reviews->moderate($review, $status);

        return $this->success(
            new AdminReviewResource($review->load(['client', 'agent', 'agentProfile', 'reviewer', 'reviewee'])),
            'Review status updated',
        );
    }
}
