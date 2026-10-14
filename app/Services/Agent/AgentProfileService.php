<?php

namespace App\Services\Agent;

use App\Enums\AgentProfileStatus;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Legal\PublicOfferService;
use Illuminate\Validation\ValidationException;

class AgentProfileService
{
    public function __construct(
        private readonly PublicOfferService $offers,
    ) {}

    public function findForUser(User $user): ?AgentProfile
    {
        return $user->profile()->with(AgentProfile::PROFILE_RELATIONS)->first();
    }

    /**
     * Phase 1 — submit a new verification application (KYC only) for a user
     * that does not yet have a profile. Starts in the pending state.
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(User $user, array $data, ?string $ip = null): AgentProfile
    {
        unset($data['accept_offer']);

        if ($user->profile()->exists()) {
            throw ValidationException::withMessages([
                'company_name' => ['You already have an agent profile.'],
            ]);
        }

        // Applying makes the user an agent so their (pending) provider surface
        // shows immediately — mirrors the individual flow. Real powers (bidding,
        // verified badge) stay gated by the APPROVED profile, so the role alone
        // grants nothing until an admin approves. PROFILE_ARCHITECTURE.md §4.
        $user->grantRole(Role::Agent);
        $user->role = Role::Agent;
        $user->role_selected_at ??= now();
        $user->save();

        /** @var AgentProfile $profile */
        $profile = $user->profile()->create([
            ...$data,
            'provider_type' => ProviderType::Agent,
            'status' => AgentProfileStatus::Pending,
        ]);

        // Submitting the application is the click-wrap acceptance of the
        // agency partnership offer (the request requires `accept_offer`).
        // That acceptance is the agent's agreement with the platform — no
        // separate contract to sign and re-upload.
        $this->acceptOffer($profile, $ip);

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }

    /**
     * Resubmit / edit the verification application (KYC). Approved profiles are
     * locked — their KYC data can only be changed by an admin. Resubmitting
     * moves the profile back to pending and clears any rejection reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function resubmit(AgentProfile $profile, array $data, ?string $ip = null): AgentProfile
    {
        unset($data['accept_offer']);

        // An APPROVED individual executor keeps working while a manager reviews
        // its legal KYC — that goes through the upgrade request
        // (AgentProfileUpgradeService), never through this form.
        if ($profile->isIndividual() && $profile->status === AgentProfileStatus::Approved) {
            throw ValidationException::withMessages([
                'profile' => ['Approved individual executors request the legal-entity status via /agent/profile/upgrade.'],
            ]);
        }

        if ($profile->status === AgentProfileStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['Approved profiles cannot be edited from the application form.'],
            ]);
        }

        // A not-yet-approved individual application has no work at stake, so
        // submitting the full legal KYC simply switches it to the legal track.
        $profile->fill($data);
        $profile->provider_type = ProviderType::Agent;
        $profile->status = AgentProfileStatus::Pending;
        $profile->rejection_reason = null;
        $profile->save();

        $this->acceptOffer($profile, $ip);

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }

    /**
     * Record the agent's acceptance of the current agency partnership offer:
     * version + hash of the exact text shown + when/from where. Re-accepting
     * (resubmit, or after a version bump) overwrites the previous record.
     */
    public function acceptOffer(AgentProfile $profile, ?string $ip = null): AgentProfile
    {
        $profile->forceFill([
            'offer_version' => $this->offers->version(PublicOfferService::AGENT),
            'offer_hash' => $this->offers->hash(PublicOfferService::AGENT),
            'offer_accepted_at' => now(),
            'offer_accepted_ip' => $ip,
        ])->save();

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

        // Both executor tracks may list any active category; what separates
        // them is Tender eligibility (AgentProfile::canBidOnTender()).
        if ($categoryIds !== null) {
            $profile->categories()->sync($categoryIds);
        }

        if ($advantageIds !== null) {
            $profile->advantages()->sync($advantageIds);
        }

        return $profile->load(AgentProfile::PROFILE_RELATIONS);
    }
}
