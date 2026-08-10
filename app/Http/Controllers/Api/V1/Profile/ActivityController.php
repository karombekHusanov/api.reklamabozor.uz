<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\UserActivityResource;
use App\Services\Activity\UserActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController extends ApiController
{
    public function __construct(
        private readonly UserActivityService $activity,
    ) {}

    /**
     * GET /me/activity(?role=&agent_profile_id=) — aggregated counters for badges / home.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'agent_profile_id' => ['nullable', 'integer'],
        ]);

        $role = isset($validated['role']) ? Role::from($validated['role']) : null;
        $agentProfileId = isset($validated['agent_profile_id'])
            ? (int) $validated['agent_profile_id']
            : null;

        $payload = $this->activity->forUser(
            $request->user(),
            $role,
            $agentProfileId,
        );

        return $this->success(new UserActivityResource($payload));
    }

    /**
     * POST /me/activity/live-orders/seen — clear the Live Orders "new" badge.
     */
    public function markLiveOrdersSeen(Request $request): JsonResponse
    {
        $result = $this->activity->markLiveOrdersSeen($request->user());

        return $this->success($result);
    }
}
