<?php

namespace App\Services\Agent;

use App\Enums\AgentContractStatus;
use App\Enums\AgentProfileStatus;
use App\Enums\AgentProfileUpgradeStatus;
use App\Enums\ProviderType;
use App\Models\AgentProfile;
use App\Models\AgentProfileUpgrade;
use App\Models\User;
use App\Services\File\FileService;
use App\Services\Legal\PublicOfferService;
use App\Services\Order\OrderNotifier;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raising an approved individual executor to the legal-entity track. The
 * request carries the full legal KYC; the profile keeps working (Tezkor) while
 * it is reviewed. Approval copies the KYC onto the SAME profile row and flips
 * provider_type individual → agent — the only provider_type transition there
 * is (one-way, manager-approved) — so reputation, categories, portfolio and
 * order history stay with the executor. Everything that depends on the track
 * (Tender eligibility, person type, contract/offer gates) is derived from
 * provider_type and follows automatically.
 */
class AgentProfileUpgradeService
{
    public function __construct(
        private readonly PublicOfferService $offers,
        private readonly AdminNotifier $admin,
        private readonly OrderNotifier $notifier,
        private readonly FileService $files,
    ) {}

    /**
     * Submit (or, while still pending, revise) the upgrade request. Submitting
     * is the click-wrap acceptance of the agency partnership offer.
     *
     * @param  array<string, mixed>  $data  validated StoreAgentProfileUpgradeRequest payload
     */
    public function submit(AgentProfile $profile, array $data, ?string $ip = null): AgentProfileUpgrade
    {
        [$upgrade, $created] = DB::transaction(function () use ($profile, $data, $ip): array {
            /** @var AgentProfile $profile */
            $profile = AgentProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if (! $profile->isIndividual() || $profile->status !== AgentProfileStatus::Approved) {
                throw ValidationException::withMessages([
                    'profile' => ['Only an approved individual executor can request the legal-entity status.'],
                ]);
            }

            $attributes = [
                ...array_intersect_key($data, array_flip(AgentProfileUpgrade::KYC_FIELDS)),
                'offer_version' => $this->offers->version(PublicOfferService::AGENT),
                'offer_hash' => $this->offers->hash(PublicOfferService::AGENT),
                'offer_accepted_at' => now(),
                'offer_accepted_ip' => $ip,
            ];

            // One open request at a time: a pending one is revised in place;
            // after a rejection a fresh request is opened (history is kept).
            $pending = $profile->upgrades()
                ->where('status', AgentProfileUpgradeStatus::Pending)
                ->first();

            if ($pending !== null) {
                $pending->update($attributes);
                $this->files->privatizeAttached($pending);

                return [$pending, false];
            }

            $upgrade = $profile->upgrades()->create([
                ...$attributes,
                'user_id' => $profile->user_id,
                'status' => AgentProfileUpgradeStatus::Pending,
            ]);

            // KYC scans came through the generic (public) upload.
            $this->files->privatizeAttached($upgrade);

            return [$upgrade, true];
        });

        if ($created) {
            $this->admin->profileUpgradeRequested($upgrade);
        }

        return $upgrade;
    }

    /**
     * Manager approves: the profile becomes a legal-entity executor.
     */
    public function approve(AgentProfileUpgrade $upgrade, User $reviewer): AgentProfileUpgrade
    {
        $upgrade = DB::transaction(function () use ($upgrade, $reviewer): AgentProfileUpgrade {
            $upgrade = $this->lockPending($upgrade);

            /** @var AgentProfile $profile */
            $profile = AgentProfile::query()->lockForUpdate()->findOrFail($upgrade->agent_profile_id);

            if (! $profile->isIndividual()) {
                throw ValidationException::withMessages([
                    'profile' => ['This profile is no longer on the individual track.'],
                ]);
            }

            $profile->forceFill([
                ...$upgrade->only(AgentProfileUpgrade::KYC_FIELDS),
                'provider_type' => ProviderType::Agent,
                'offer_version' => $upgrade->offer_version,
                'offer_hash' => $upgrade->offer_hash,
                'offer_accepted_at' => $upgrade->offer_accepted_at,
                'offer_accepted_ip' => $upgrade->offer_accepted_ip,
                // Approving the KYC approves the platform agreement — same as
                // a direct legal-entity approval (AgentAdminService::updateStatus).
                'contract_status' => AgentContractStatus::Approved,
                'contract_rejection_reason' => null,
            ])->save();

            $upgrade->forceFill([
                'status' => AgentProfileUpgradeStatus::Approved,
                'rejection_reason' => null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return $upgrade;
        });

        $this->notifier->notifyProfileUpgradeDecision($upgrade);

        return $upgrade;
    }

    /**
     * Manager rejects with a reason; the profile is untouched and keeps
     * working as an individual executor.
     */
    public function reject(AgentProfileUpgrade $upgrade, User $reviewer, string $reason): AgentProfileUpgrade
    {
        $upgrade = DB::transaction(function () use ($upgrade, $reviewer, $reason): AgentProfileUpgrade {
            $upgrade = $this->lockPending($upgrade);

            $upgrade->forceFill([
                'status' => AgentProfileUpgradeStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            return $upgrade;
        });

        $this->notifier->notifyProfileUpgradeDecision($upgrade);

        return $upgrade;
    }

    private function lockPending(AgentProfileUpgrade $upgrade): AgentProfileUpgrade
    {
        /** @var AgentProfileUpgrade $locked */
        $locked = AgentProfileUpgrade::query()->lockForUpdate()->findOrFail($upgrade->id);

        if (! $locked->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['This request has already been reviewed.'],
            ]);
        }

        return $locked;
    }
}
