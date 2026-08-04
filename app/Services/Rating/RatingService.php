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

        foreach ($user->allRoles() as $role) {
            if ($role === Role::Admin) {
                continue;
            }

            if (in_array($role, [Role::Agent, Role::Designer, Role::Seller], true)) {
                $profiles = AgentProfile::where('user_id', $userId)->get();
                $matched = false;

                foreach ($profiles as $profile) {
                    // Only pair a role with a profile of the same provider_type.
                    if ($profile->provider_type?->value === $role->value) {
                        $this->recomputeOne($userId, $role, $profile->id);
                        $matched = true;
                    }
                }

                if (! $matched) {
                    $this->recomputeOne($userId, $role, null);
                }
            } else {
                $this->recomputeOne($userId, $role, null);
            }
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
