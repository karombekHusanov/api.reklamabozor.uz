<?php

namespace App\Services\Admin;

use App\Enums\AgentContractStatus;
use App\Enums\AgentProfileStatus;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgentAdminService
{
    /**
     * @param  array{
     *     status?: string|null,
     *     search?: string|null,
     *     per_page?: int,
     *     sort?: string,
     *     direction?: string
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = AgentProfile::query()
            ->with(['user', ...AgentProfile::PROFILE_RELATIONS]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $likeTerm = '%'.mb_strtolower($filters['search']).'%';

            $query->where(function ($builder) use ($likeTerm): void {
                $builder
                    ->whereRaw('LOWER(company_name) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(location_label) LIKE ?', [$likeTerm])
                    ->orWhereHas('user', function ($userQuery) use ($likeTerm): void {
                        $userQuery
                            ->whereRaw('LOWER(first_name) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(username) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$likeTerm]);
                    });
            });
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function updateStatus(
        AgentProfile $profile,
        AgentProfileStatus $status,
        ?string $rejectionReason = null,
    ): AgentProfile {
        if ($status === AgentProfileStatus::Rejected && blank($rejectionReason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is required.'],
            ]);
        }

        // An agent cannot be activated until they have uploaded a signed
        // agreement for the manager to review (designers are exempt).
        if (
            $status === AgentProfileStatus::Approved
            && $profile->requiresContract()
            && $profile->contract_status !== AgentContractStatus::UnderReview
            && $profile->contract_status !== AgentContractStatus::Approved
        ) {
            throw ValidationException::withMessages([
                'contract' => ['The agent has not uploaded a signed agreement yet.'],
            ]);
        }

        // …nor before they accepted the current agency partnership offer.
        if (
            $status === AgentProfileStatus::Approved
            && ! $profile->hasAcceptedCurrentOffer()
        ) {
            throw ValidationException::withMessages([
                'offer' => ['The agent has not accepted the current agency offer yet.'],
            ]);
        }

        $profile->status = $status;

        if ($status === AgentProfileStatus::Approved) {
            $profile->approved_at = now();
            $profile->rejection_reason = null;

            // Approving the agent also approves the signed agreement.
            if ($profile->requiresContract()) {
                $profile->contract_status = AgentContractStatus::Approved;
                $profile->contract_rejection_reason = null;
            }

            // Multirole: agent is granted on top of whatever the user already
            // holds (e.g. client) and becomes the active role.
            $user = $profile->user;
            $user->grantRole(Role::Agent);
            $user->role = Role::Agent;
            $user->save();
        }

        if ($status === AgentProfileStatus::Rejected) {
            $profile->rejection_reason = $rejectionReason;
            $profile->approved_at = null;

            $user = $profile->user;

            if ($user->hasRole(Role::Agent)) {
                $user->revokeRole(Role::Agent);
                $user->save();
            }
        }

        if ($status === AgentProfileStatus::Pending) {
            $profile->rejection_reason = null;
            $profile->approved_at = null;
        }

        $profile->save();

        return $profile->load(['user', ...AgentProfile::PROFILE_RELATIONS]);
    }

    /**
     * Manager rejects the uploaded signed agreement (e.g. missing signature or
     * stamp, unreadable scan) without rejecting the KYC — the agent re-uploads.
     */
    public function rejectContract(AgentProfile $profile, string $reason): AgentProfile
    {
        if ($profile->contract_status !== AgentContractStatus::UnderReview) {
            throw ValidationException::withMessages([
                'contract' => ['There is no signed agreement awaiting review.'],
            ]);
        }

        $profile->contract_status = AgentContractStatus::Rejected;
        $profile->contract_rejection_reason = $reason;
        $profile->save();

        return $profile->load(['user', ...AgentProfile::PROFILE_RELATIONS]);
    }

    /**
     * Manager-created agent: a new agent user plus an approved KYC profile.
     * No Telegram identity yet — it links up when the person first opens the
     * mini app with the same phone number.
     *
     * @param  array<string, mixed>  $data  validated StoreAgentRequest payload
     */
    public function create(array $data): AgentProfile
    {
        return DB::transaction(function () use ($data): AgentProfile {
            $user = User::create([
                'first_name' => $data['director_name'],
                // Same "+digits" shape the Telegram webhook stores, so the
                // account links up when the person shares this phone in the bot.
                'phone' => $this->normalizePhone($data['phone']),
                'role' => Role::Agent,
                'roles' => [Role::Agent],
                'role_selected_at' => now(),
                'is_active' => true,
            ]);

            /** @var AgentProfile $profile */
            $profile = $user->profile()->create([
                ...$data,
                'provider_type' => ProviderType::Agent,
                'status' => AgentProfileStatus::Approved,
                'approved_at' => now(),
                // Manager vouches for this agent — no self-sign handshake needed.
                'contract_status' => AgentContractStatus::Approved,
            ]);

            return $profile->load(['user', ...AgentProfile::PROFILE_RELATIONS]);
        });
    }

    /**
     * "+998 90 777-11-22" → "+998907771122" — mirrors the Telegram webhook.
     */
    private function normalizePhone(string $raw): string
    {
        return '+'.preg_replace('/[^0-9]/', '', $raw);
    }
}
