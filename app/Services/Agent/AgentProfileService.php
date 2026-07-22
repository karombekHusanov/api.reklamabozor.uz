<?php

namespace App\Services\Agent;

use App\Enums\AgentProfileStatus;
use App\Enums\ProviderType;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AgentProfileService
{
    public function findForUser(User $user): ?AgentProfile
    {
        return $user->agentProfile()->with(AgentProfile::PROFILE_RELATIONS)->first();
    }

    /**
     * Phase 1 — submit a new verification application (KYC only) for a user
     * that does not yet have a profile. Starts in the pending state.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(User $user, array $data): AgentProfile
    {
        if ($user->agentProfile()->exists()) {
            throw ValidationException::withMessages([
                'company_name' => ['You already have an agent profile.'],
            ]);
        }

        /** @var AgentProfile $profile */
        $profile = $user->agentProfile()->create([
            ...$data,
            'provider_type' => ProviderType::Agent,
            'status' => AgentProfileStatus::Pending,
        ]);

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }

    /**
     * Resubmit / edit the verification application (KYC). Approved profiles are
     * locked — their KYC data can only be changed by an admin. Resubmitting
     * moves the profile back to pending and clears any rejection reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function resubmit(AgentProfile $profile, array $data): AgentProfile
    {
        if ($profile->status === AgentProfileStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['Approved profiles cannot be edited from the application form.'],
            ]);
        }

        $profile->fill($data);
        $profile->status = AgentProfileStatus::Pending;
        $profile->rejection_reason = null;
        $profile->save();

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }

    /**
     * Phase 2 — update the client-facing presentation (logo, location, bio,
     * categories, ...). Only available to approved profiles; does not change
     * the verification status.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDetails(AgentProfile $profile, array $data): AgentProfile
    {
        $categoryIds = $data['category_ids'] ?? null;
        $advantageIds = $data['advantage_ids'] ?? null;
        unset($data['category_ids'], $data['advantage_ids']);

        $profile->fill($data);
        $profile->save();

        if ($categoryIds !== null) {
            $profile->categories()->sync($categoryIds);
        }

        if ($advantageIds !== null) {
            $profile->advantages()->sync($advantageIds);
        }

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }
}
