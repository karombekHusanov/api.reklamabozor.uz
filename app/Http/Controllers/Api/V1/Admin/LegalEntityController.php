<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LegalEntityStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\LegalEntityVerificationResource;
use App\Models\LegalEntityVerification;
use App\Services\LegalEntity\LegalEntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LegalEntityController extends ApiController
{
    public function __construct(
        private readonly LegalEntityService $service,
    ) {}

    /**
     * Legal-entity verification queue — filterable by status, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(LegalEntityStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = LegalEntityVerification::query()
            ->with(['user', 'registrationCertificateFile'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return $this->success([
            'items' => LegalEntityVerificationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Approve (mark the user a verified legal entity) or reject (with a reason).
     */
    public function updateStatus(Request $request, LegalEntityVerification $legalEntityVerification): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([LegalEntityStatus::Approved->value, LegalEntityStatus::Rejected->value])],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->moderate(
            $legalEntityVerification,
            LegalEntityStatus::from($validated['status']),
            $validated['rejection_reason'] ?? null,
        );

        return $this->success(
            new LegalEntityVerificationResource($legalEntityVerification->load(['user', 'registrationCertificateFile'])),
            'Verification status updated',
        );
    }
}
