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

        // Provider reputation is per-profile (1 user = 1 profile): map any
        // agent/designer query to the profile's canonical kind so the single
        // combined provider rating row is returned regardless of which provider
        // role the caller asked for (capability comes from categories).
        if ($role !== null && in_array($role, [Role::Agent, Role::Designer], true)) {
            $profile = $request->user()->profile;
            if ($profile !== null) {
                $role = $profile->provider_type->toRole();
            }
        }

        $ratings = $this->ratings->allForUser($request->user()->id, $role);

        return $this->success(UserRatingResource::collection($ratings));
    }
}
