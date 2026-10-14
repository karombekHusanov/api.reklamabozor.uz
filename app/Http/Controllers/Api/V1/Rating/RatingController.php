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

        // Provider reputation is per-profile (1 user = 1 profile): map an agent
        // query to the profile's canonical role so the single provider rating
        // row is returned.
        if ($role === Role::Agent) {
            $profile = $request->user()->profile;
            if ($profile !== null) {
                $role = $profile->provider_type->toRole();
            }
        }

        $ratings = $this->ratings->allForUser($request->user()->id, $role);

        return $this->success(UserRatingResource::collection($ratings));
    }
}
