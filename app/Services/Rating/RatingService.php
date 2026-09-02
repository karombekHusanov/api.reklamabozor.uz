<?php

namespace App\Services\Rating;

use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\UserRating;
use Illuminate\Database\Eloquent\Collection;

/**
 * Orchestrates Stars + Grade recompute and persists the cache row.
 * Called on review approve, order complete/cancel/dispute, refund.
 */
class RatingService
{
    public function __construct(
        private readonly StarsCalculator $stars,
        private readonly GradeCalculator $grade,
    ) {}

    /**
     * Recompute all rating rows for a user (every held role + every profile).
     */
    public function recomputeForUser(int $userId): void
    {
        $user = User::find($userId);
        if ($user === null) {
            return;
        }

        // 1 user = 1 profile (Variant 1): agent/designer reputation is a single
        // combined rating owned by that one profile — computed once under the
        // profile's own kind, not paired per provider role. Client (and the
        // deferred seller stub) stay role-keyed with no profile.
        $profile = AgentProfile::where('user_id', $userId)->first();

        foreach ($user->allRoles() as $role) {
            if ($role === Role::Admin) {
                continue;
            }

            // Agent/designer with a profile: handled once after the loop, so a
            // held provider role that isn't the profile's kind doesn't create a
            // stray null-profile row (capability comes from categories).
            if ($profile !== null && in_array($role, [Role::Agent, Role::Designer], true)) {
                continue;
            }

            // Client, the deferred seller stub, or a KYC-pending provider with no
            // profile yet — seed a role-keyed row with no profile.
            $this->recomputeOne($userId, $role, null);
        }

        if ($profile !== null) {
            $providerRole = $profile->provider_type->toRole();
            $this->recomputeOne($userId, $providerRole, $profile->id);

            // Prune any stale agent/designer rows that aren't this canonical
            // profile row (an old null-profile stub, or the other kind) so
            // cachedRating() and /me/rating resolve to exactly one provider row.
            UserRating::where('user_id', $userId)
                ->whereIn('role', [Role::Agent->value, Role::Designer->value])
                ->where(fn ($query) => $query
                    ->where('role', '!=', $providerRole->value)
                    ->orWhereNull('agent_profile_id')
                    ->orWhere('agent_profile_id', '!=', $profile->id))
                ->delete();
        }
    }

    /**
     * Recompute a single role+profile cache row.
     */
    public function recomputeOne(int $userId, Role $role, ?int $agentProfileId): UserRating
    {
        $starsResult = $this->stars->compute($userId, $role, $agentProfileId);
        $gradeResult = $this->grade->compute($userId, $role, $starsResult['stars'], $agentProfileId);

        $starsBoost = StarsCalculator::listingBoost($starsResult['stars']);
        $gradeBoost = GradeCalculator::listingBoost($gradeResult);
        $totalBoost = $starsBoost + $gradeBoost;

        return UserRating::updateOrCreate(
            [
                'user_id' => $userId,
                'role' => $role,
                'agent_profile_id' => $agentProfileId,
            ],
            [
                'stars' => $starsResult['stars'],
                'stars_count' => $starsResult['count'],
                'grade' => $gradeResult,
                'listing_boost' => $totalBoost,
            ],
        );
    }

    /**
     * Get the cached rating for a user+role (optional profile).
     */
    public function getCached(int $userId, Role $role, ?int $agentProfileId = null): ?UserRating
    {
        return UserRating::where('user_id', $userId)
            ->where('role', $role)
            ->where('agent_profile_id', $agentProfileId)
            ->first();
    }

    /**
     * Get all cached ratings for a user, optionally filtered by role.
     *
     * @return Collection<int, UserRating>
     */
    public function allForUser(int $userId, ?Role $role = null)
    {
        return UserRating::where('user_id', $userId)
            ->when($role, fn ($q) => $q->where('role', $role))
            ->get();
    }
}
