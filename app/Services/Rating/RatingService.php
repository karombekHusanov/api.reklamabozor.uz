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

        // 1 user = 1 profile (Variant 1): executor reputation is a single rating
        // owned by that one profile — computed once under the agent role, for
        // either KYC track. Client stays role-keyed with no profile.
        $profile = AgentProfile::where('user_id', $userId)->first();

        foreach ($user->allRoles() as $role) {
            if ($role === Role::Admin) {
                continue;
            }

            // Executor with a profile: handled once after the loop, so the held
            // agent role doesn't also create a stray null-profile row.
            if ($profile !== null && $role === Role::Agent) {
                continue;
            }

            // Client, or a KYC-pending provider with no profile yet — seed a
            // role-keyed row with no profile.
            $this->recomputeOne($userId, $role, null);
        }

        if ($profile !== null) {
            $providerRole = $profile->provider_type->toRole();
            $this->recomputeOne($userId, $providerRole, $profile->id);

            // Prune any stale provider rows that aren't this canonical profile
            // row (an old null-profile stub) so cachedRating() and /me/rating
            // resolve to exactly one provider row.
            UserRating::where('user_id', $userId)
                ->where('role', $providerRole->value)
                ->where(fn ($query) => $query
                    ->whereNull('agent_profile_id')
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
