<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AgentProfileUpgradeStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\AgentProfileUpgradeResource;
use App\Models\AgentProfileUpgrade;
use App\Services\Agent\AgentProfileUpgradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manager review of individual executors' requests for the legal-entity
 * status (provider_type individual → agent).
 */
class AgentProfileUpgradeController extends ApiController
{
    private const RELATIONS = ['user', 'agentProfile', 'directorPassportFile', 'registrationCertificateFile'];

    public function __construct(
        private readonly AgentProfileUpgradeService $upgrades,
    ) {}

    /**
     * Upgrade request queue — filterable by status, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(AgentProfileUpgradeStatus::class)],
            'agent_profile_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = AgentProfileUpgrade::query()
            ->with(self::RELATIONS)
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['agent_profile_id'] ?? null, fn ($q, $id) => $q->where('agent_profile_id', $id))
            ->latest()
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15);

        return $this->success([
            'items' => AgentProfileUpgradeResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(AgentProfileUpgrade $agentProfileUpgrade): JsonResponse
    {
        return $this->success(new AgentProfileUpgradeResource($agentProfileUpgrade->load(self::RELATIONS)));
    }

    public function approve(Request $request, AgentProfileUpgrade $agentProfileUpgrade): JsonResponse
    {
        $upgrade = $this->upgrades->approve($agentProfileUpgrade, $request->user());

        return $this->success(
            new AgentProfileUpgradeResource($upgrade->load(self::RELATIONS)),
            'Legal-entity status approved',
        );
    }

    public function reject(Request $request, AgentProfileUpgrade $agentProfileUpgrade): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $upgrade = $this->upgrades->reject($agentProfileUpgrade, $request->user(), $validated['rejection_reason']);

        return $this->success(
            new AgentProfileUpgradeResource($upgrade->load(self::RELATIONS)),
            'Legal-entity request rejected',
        );
    }
}
