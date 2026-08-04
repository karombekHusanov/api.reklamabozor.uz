<?php

namespace App\Http\Controllers\Api\V1\Rating;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\UserRatingResource;
use App\Services\Rating\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RatingController extends ApiController
{
    public function __construct(
        private readonly RatingService $ratings,
    ) {}

    /**
     * GET /me/rating(?role=) — the authenticated user's cached rating(s).
     */
    public function me(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
        ]);

        $role = isset($validated['role']) ? Role::from($validated['role']) : null;
        $ratings = $this->ratings->allForUser($request->user()->id, $role);

        return $this->success(UserRatingResource::collection($ratings));
    }
}
