<?php

namespace App\Http\Controllers\Api\V1\Individual;

use App\Enums\AgentProfileStatus;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Individual\StoreIndividualProfileRequest;
use App\Http\Resources\AgentProfileResource;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\File\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Individual ("jismoniy") executors: a light passport+selfie KYC, admin-approved
 * like an agency (no instant approval). The profile holds the agent role but is
 * Tezkor-only — it cannot bid on Tender orders and has no contract/payout/bank.
 */
class IndividualProfileController extends ApiController
{
    public function __construct(
        private readonly FileService $files,
    ) {}

    public function store(StoreIndividualProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if (AgentProfile::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'profile' => ['You already have a provider profile.'],
            ]);
        }

        // Creating an individual profile IS becoming a provider — grant the
        // agent role here (mirrors the legal-entity flow). No self-select step,
        // no PATCH /me/role. PROFILE_ARCHITECTURE.md §4.
        $user->grantRole(Role::Agent);
        $user->role = Role::Agent;
        $user->role_selected_at ??= now();
        $user->save();

        $profile = AgentProfile::create($this->attributes($request, $user));

        // Passport + selfie came through the generic (public) upload — KYC
        // scans belong behind the authenticated file endpoint.
        $this->files->privatizeAttached($profile);

        $profile->categories()->sync($request->validated()['category_ids']);

        return $this->success(
            new AgentProfileResource($profile->load(AgentProfile::PROFILE_RELATIONS)),
            'Individual profile submitted',
            201,
        );
    }

    /**
     * Resubmit / edit the application while pending or rejected. Approved
     * profiles are locked — KYC can only be changed by an admin. Resubmitting
     * moves a rejected profile back to pending.
     */
    public function update(StoreIndividualProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $profile = AgentProfile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return $this->error('You have no individual application to update.', 404);
        }

        // Only the individual track is edited here — a legal-entity profile
        // must never be downgraded through this form.
        if (! $profile->isIndividual()) {
            throw ValidationException::withMessages([
                'profile' => ['Legal-entity executor applications are edited via the agent form.'],
            ]);
        }

        if ($profile->status === AgentProfileStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['Approved profiles cannot be edited from the application form.'],
            ]);
        }

        $profile->update([
            ...$this->attributes($request, $user),
            'rejection_reason' => null,
        ]);

        $this->files->privatizeAttached($profile);

        $profile->categories()->sync($request->validated()['category_ids']);

        return $this->success(
            new AgentProfileResource($profile->fresh()->load(AgentProfile::PROFILE_RELATIONS)),
            'Individual application updated',
        );
    }

    /**
     * Map the request onto the stored profile columns. full_name reuses the
     * director_name column and the passport scan the director_passport_file_id
     * column, so the individual track shares the agent profile table.
     *
     * @return array<string, mixed>
     */
    private function attributes(StoreIndividualProfileRequest $request, User $user): array
    {
        $validated = $request->validated();

        return [
            'user_id' => $user->id,
            'provider_type' => ProviderType::Individual,
            'director_name' => $validated['full_name'],
            'director_passport_file_id' => $validated['passport_file_id'],
            'selfie_file_id' => $validated['selfie_file_id'],
            'alt_contact' => $validated['alt_contact'] ?? null,
            'phone' => $user->phone,
            'status' => AgentProfileStatus::Pending,
        ];
    }
}
