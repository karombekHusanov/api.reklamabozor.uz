<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AgentProfileStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexAgentProfilesRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAgentProfileStatusRequest;
use App\Http\Resources\AdminAgentProfileResource;
use App\Models\AgentProfile;
use App\Services\Admin\AgentAdminService;
use Illuminate\Http\JsonResponse;

class AgentProfileController extends ApiController
{
    public function __construct(
        private readonly AgentAdminService $agentAdminService,
    ) {}

    public function index(IndexAgentProfilesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->agentAdminService->list([
            'status' => $validated['status'] ?? null,
            'search' => $validated['search'] ?? null,
            'per_page' => $validated['per_page'] ?? 15,
            'sort' => $validated['sort'] ?? 'created_at',
            'direction' => $validated['direction'] ?? 'desc',
        ]);

        return $this->success([
            'items' => AdminAgentProfileResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(AgentProfile $agentProfile): JsonResponse
    {
        $agentProfile->load(['user', ...AgentProfile::PROFILE_RELATIONS]);

        return $this->success(new AdminAgentProfileResource($agentProfile));
    }

    public function updateStatus(UpdateAgentProfileStatusRequest $request, AgentProfile $agentProfile): JsonResponse
    {
        $validated = $request->validated();

        $updated = $this->agentAdminService->updateStatus(
            $agentProfile->load('user'),
            AgentProfileStatus::from($validated['status']),
            $validated['rejection_reason'] ?? null,
        );

        $message = match ($updated->status) {
            AgentProfileStatus::Approved => 'Agent application approved',
            AgentProfileStatus::Rejected => 'Agent application rejected',
            AgentProfileStatus::Pending => 'Agent application moved to pending',
        };

        return $this->success(new AdminAgentProfileResource($updated), $message);
    }
}
