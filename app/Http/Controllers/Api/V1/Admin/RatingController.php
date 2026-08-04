<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Resources\UserRatingResource;
use App\Models\User;
use App\Services\Rating\RatingService;
use Illuminate\Http\JsonResponse;

class RatingController extends ApiController
{
    public function __construct(
        private readonly RatingService $ratings,
    ) {}

    /**
     * GET /admin/users/{user}/rating — all cached ratings for this user.
     */
    public function show(User $user): JsonResponse
    {
        $ratings = $this->ratings->allForUser($user->id);

        return $this->success(UserRatingResource::collection($ratings));
    }
}
