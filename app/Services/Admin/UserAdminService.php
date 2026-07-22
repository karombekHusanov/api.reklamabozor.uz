<?php

namespace App\Services\Admin;

use App\Enums\AgentProfileStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class UserAdminService
{
    /**
     * @param  array{
     *     role?: string|null,
     *     search?: string|null,
     *     is_active?: bool|null,
     *     per_page?: int,
     *     sort?: string,
     *     direction?: string
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with(['avatarFile', 'agentProfile', 'legalEntityVerification']);

        // No role = the "all users" view: every marketplace account,
        // including agent-role users who never submitted a KYC application.
        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        } else {
            $query->where('role', '!=', Role::Admin);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $likeTerm = '%'.mb_strtolower($term).'%';

            $query->where(function ($builder) use ($term, $likeTerm): void {
                $builder
                    ->whereRaw('LOWER(first_name) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$likeTerm]);

                if (is_numeric($term)) {
                    $builder->orWhere('telegram_id', (int) $term);
                }
            });
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        return $query
            ->orderBy($sort, $direction)
            ->paginate($filters['per_page'] ?? 15);
    }

    public function update(User $user, array $data, User $actor): User
    {
        if (isset($data['role'])) {
            $data['role'] = Role::from($data['role']);
            $this->guardRoleChange($user, $data['role'], $actor);

            // Admin privileges are exclusive — demotion drops the admin role
            // from the held set. Marketplace roles accumulate (multirole).
            if ($user->role === Role::Admin && $data['role'] !== Role::Admin) {
                $user->revokeRole(Role::Admin);
            }

            // Correcting a mis-picked role: an admin may move a user to a
            // conflicting provider group (e.g. agent → designer), dropping the
            // old provider role so the result stays a valid combination.
            $this->moveProviderGroup($user, $data['role']);

            $user->grantRole($data['role']);
        }

        $user->fill($data);
        $user->save();

        return $user->refresh();
    }

    public function setActive(User $user, bool $isActive, User $actor): User
    {
        if (! $isActive) {
            $this->guardDeactivation($user, $actor);
        }

        $user->is_active = $isActive;
        $user->save();

        if (! $isActive) {
            $user->tokens()->delete();
        }

        return $user->refresh();
    }

    private function guardDeactivation(User $user, User $actor): void
    {
        if ($user->id === $actor->id) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        if ($user->role === Role::Admin && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'is_active' => ['Cannot deactivate the last active admin.'],
            ]);
        }
    }

    private function guardRoleChange(User $user, Role $newRole, User $actor): void
    {
        if ($user->id === $actor->id && $newRole !== Role::Admin) {
            throw ValidationException::withMessages([
                'role' => ['You cannot change your own admin role.'],
            ]);
        }

        if ($user->role === Role::Admin && $newRole !== Role::Admin && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => ['Cannot demote the last active admin.'],
            ]);
        }
    }

    /**
     * When an admin sets a provider role that conflicts with the user's held
     * roles (coexistence matrix), revoke the conflicting provider role(s) so the
     * user MOVES between groups (e.g. agent → designer) instead of ending up in
     * an invalid combination. Blocked while an approved provider profile of the
     * old group survives — it would linger as a ghost in the marketplace; the
     * admin must reject that profile first.
     */
    private function moveProviderGroup(User $user, Role $newRole): void
    {
        $heldConflicts = array_filter(
            $newRole->conflictingRoles(),
            fn (Role $role) => $user->hasRole($role),
        );

        if ($heldConflicts === []) {
            return;
        }

        if ($user->agentProfile()->where('status', AgentProfileStatus::Approved)->exists()) {
            throw ValidationException::withMessages([
                'role' => ["Reject this user's approved provider profile before switching their provider role."],
            ]);
        }

        foreach ($heldConflicts as $role) {
            $user->revokeRole($role);
        }
    }

    private function activeAdminCount(): int
    {
        return User::query()
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->count();
    }
}
