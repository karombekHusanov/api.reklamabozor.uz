<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\AgentProfileStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\StoreAgentProfileRequest;
use App\Http\Requests\Api\V1\Agent\UpdateAgentProfileDetailsRequest;
use App\Http\Requests\Api\V1\Agent\UpdateAgentProfileRequest;
use App\Http\Resources\AgentProfileResource;
use App\Services\Agent\AgentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentProfileController extends ApiController
{
    public function __construct(
        private readonly AgentProfileService $agentProfiles,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $profile = $this->agentProfiles->findForUser($request->user());

        return $this->success($profile ? new AgentProfileResource($profile) : null);
    }

    /**
     * Phase 1 — submit the verification application (KYC).
     */
    public function store(StoreAgentProfileRequest $request): JsonResponse
    {
        $profile = $this->agentProfiles->apply($request->user(), $request->validated());

        return $this->success(new AgentProfileResource($profile), 'Application submitted', 201);
    }

    /**
     * Phase 1 — resubmit / edit the verification application while pending or rejected.
     */
    public function update(UpdateAgentProfileRequest $request): JsonResponse
    {
        $profile = $this->agentProfiles->findForUser($request->user());

        if ($profile === null) {
            return $this->error('You have no agent application to update.', 404);
        }

        $updated = $this->agentProfiles->resubmit($profile, $request->validated());

        return $this->success(new AgentProfileResource($updated), 'Application updated');
    }

    /**
     * Phase 2 — update the client-facing presentation. Approved profiles only.
     */
    public function updateDetails(UpdateAgentProfileDetailsRequest $request): JsonResponse
    {
        $profile = $this->agentProfiles->findForUser($request->user());

        if ($profile === null) {
            return $this->error('You have no agent profile yet.', 404);
        }

        if ($profile->status !== AgentProfileStatus::Approved) {
            return $this->error('Your profile must be verified before you can edit it.', 403);
        }

        $updated = $this->agentProfiles->updateDetails($profile, $request->validated());

        return $this->success(new AgentProfileResource($updated), 'Profile updated');
    }
}
