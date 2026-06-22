<?php

namespace App\Services\Admin;

use App\Enums\AgentProfileStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

        $profile->status = $status;

        if ($status === AgentProfileStatus::Approved) {
            $profile->approved_at = now();
            $profile->rejection_reason = null;

            $profile->user()->update(['role' => Role::Agent]);
        }

        if ($status === AgentProfileStatus::Rejected) {
            $profile->rejection_reason = $rejectionReason;
            $profile->approved_at = null;

            if ($profile->user->role === Role::Agent) {
                $profile->user()->update(['role' => Role::Client]);
            }
        }

        if ($status === AgentProfileStatus::Pending) {
            $profile->rejection_reason = null;
            $profile->approved_at = null;
        }

        $profile->save();

        return $profile->load(['user', ...AgentProfile::PROFILE_RELATIONS]);
    }
}
