<?php

namespace App\Services\Rating;

use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\Review;

/**
 * Calculates the weighted Stars score (1.00–5.00) using the Yandex-style
 * formula from RATING_SYSTEM.md §3.
 *
 * R = (N0 * 5 + Σ sj * uj) / (N0 + Σ uj)
 *
 * With recency weighting: uj = 1 + α * (j-1) / max(m-1, 1)
 */
class StarsCalculator
{
    private const ALPHA = 0.5;

    /**
     * @return array{stars: float, count: int}
     */
    public function compute(int $userId, Role $role, ?int $agentProfileId = null): array
    {
        [$w, $n0] = $this->params($role);

        $query = Review::query()
            ->where('status', ReviewStatus::Approved)
            ->whereNotNull('rating')
            ->where('reviewee_id', $userId);

        if ($role === Role::Client) {
            $query->where('direction', ReviewDirection::ProviderToClient);
        } else {
            $query->where('direction', ReviewDirection::ClientToProvider);
            if ($agentProfileId !== null) {
                $query->where('agent_profile_id', $agentProfileId);
            }
        }

        // Newest W reviews, then re-order oldest→newest so recency weights apply.
        $deals = $query
            ->orderByDesc('created_at')
            ->limit($w)
            ->get(['rating', 'created_at'])
            ->sortBy('created_at')
            ->pluck('rating')
            ->values();

        $m = $deals->count();

        if ($m === 0) {
            return ['stars' => 5.00, 'count' => 0];
        }

        $sumSU = 0.0;
        $sumU = 0.0;

        // j is 0-indexed; uj = 1 + α*(j)/(m-1) ≡ 1 + α*(j_1based-1)/max(m-1,1)
        foreach ($deals as $j => $score) {
            $u = 1 + self::ALPHA * ($j / max($m - 1, 1));
            $sumSU += (float) $score * $u;
            $sumU += $u;
        }

        $r = ($n0 * 5 + $sumSU) / ($n0 + $sumU);
        $r = round($r, 1);
        $r = max(1.0, min(5.0, $r));

        return ['stars' => $r, 'count' => $m];
    }

    /**
     * Listing boost from Stars score — RATING_SYSTEM.md §3.5.
     */
    public static function listingBoost(float $stars): int
    {
        if ($stars >= 4.80) {
            return 4;
        }
        if ($stars >= 4.50) {
            return 0;
        }
        if ($stars >= 4.20) {
            return -4;
        }

        return -8;
    }

    /**
     * @return array{0: int, 1: int} [W window, N0 seed]
     */
    private function params(Role $role): array
    {
        if ($role === Role::Client) {
            return [40, 20];
        }

        return [100, 50];
    }
}
