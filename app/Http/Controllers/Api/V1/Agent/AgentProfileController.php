<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\AgentProfileStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\AcceptAgentOfferRequest;
use App\Http\Requests\Api\V1\Agent\StoreAgentProfileRequest;
use App\Http\Requests\Api\V1\Agent\StoreAgentProfileUpgradeRequest;
use App\Http\Requests\Api\V1\Agent\UpdateAgentProfileDetailsRequest;
use App\Http\Requests\Api\V1\Agent\UpdateAgentProfileRequest;
use App\Http\Requests\Api\V1\Agent\UploadSignedContractRequest;
use App\Http\Resources\AgentProfileResource;
use App\Http\Resources\AgentProfileUpgradeResource;
use App\Models\File;
use App\Services\Agent\AgentContractService;
use App\Services\Agent\AgentProfileService;
use App\Services\Agent\AgentProfileUpgradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentProfileController extends ApiController
{
    public function __construct(
        private readonly AgentProfileService $agentProfiles,
        private readonly AgentContractService $contracts,
        private readonly AgentProfileUpgradeService $upgrades,
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
        $profile = $this->agentProfiles->apply($request->user(), $request->validated(), $request->ip());

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

        $updated = $this->agentProfiles->resubmit($profile, $request->validated(), $request->ip());

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

    /**
     * Accept the current agency partnership offer — for profiles that applied
     * before the offer existed, or after its version was bumped.
     */
    public function acceptOffer(AcceptAgentOfferRequest $request): JsonResponse
    {
        $profile = $this->agentProfiles->findForUser($request->user());

        if ($profile === null || ! $profile->requiresContract()) {
            return $this->error('You have no agent profile yet.', 404);
        }

        $updated = $this->agentProfiles->acceptOffer($profile, $request->ip());

        return $this->success(new AgentProfileResource($updated), 'Offer accepted');
    }

    /**
     * The latest request to be raised to the legal-entity track (or null).
     */
    public function showUpgrade(Request $request): JsonResponse
    {
        $upgrade = $request->user()->profile?->latestUpgrade;

        return $this->success($upgrade ? new AgentProfileUpgradeResource($upgrade) : null);
    }

    /**
     * An approved individual executor submits the full legal KYC to become a
     * legal-entity executor (Tender access). The profile keeps working while
     * the manager reviews it.
     */
    public function requestUpgrade(StoreAgentProfileUpgradeRequest $request): JsonResponse
    {
        $profile = $this->agentProfiles->findForUser($request->user());

        if ($profile === null) {
            return $this->error('You have no executor profile yet.', 404);
        }

        $upgrade = $this->upgrades->submit($profile, $request->validated(), $request->ip());

        return $this->success(
            new AgentProfileUpgradeResource($upgrade),
            'Legal-entity request submitted',
            $upgrade->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Agent uploads their signed (wet-signature + stamp) agreement scan.
     */
    public function uploadSignedContract(UploadSignedContractRequest $request): JsonResponse
    {
        $profile = $this->agentProfiles->findForUser($request->user());

        if ($profile === null) {
            return $this->error('You have no agent profile yet.', 404);
        }

        /** @var File $file */
        $file = File::findOrFail($request->validated()['file_id']);

        $updated = $this->contracts->uploadSigned($profile, $file);

        return $this->success(new AgentProfileResource($updated), 'Signed agreement uploaded');
    }
}
