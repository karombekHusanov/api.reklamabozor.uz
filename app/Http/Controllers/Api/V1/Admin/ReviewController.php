<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\AdminReviewResource;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends ApiController
{
    /**
     * Moderation queue — filterable by status, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ReviewStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Review::query()
            ->with(['client', 'agent', 'agentProfile'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
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
     * Approve or reject a review. Approving makes it public and counts it
     * into the agency's average rating.
     */
    public function updateStatus(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([ReviewStatus::Approved->value, ReviewStatus::Rejected->value])],
        ]);

        $review->update(['status' => ReviewStatus::from($validated['status'])]);

        return $this->success(
            new AdminReviewResource($review->load(['client', 'agent', 'agentProfile'])),
            'Review status updated',
        );
    }
}
