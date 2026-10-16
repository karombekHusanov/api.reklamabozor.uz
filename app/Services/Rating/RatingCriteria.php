<?php

namespace App\Services\Rating;

use App\Enums\Role;

/**
 * Rating criteria definitions per role — codes and weights from RATING_SYSTEM.md §2.
 * Each criterion is scored 1–5 by the counterparty; the weighted sum yields deal_score.
 */
class RatingCriteria
{
    /**
     * @return list<array{code: string, weight: float}>
     */
    public static function forRole(Role $role): array
    {
        return match ($role) {
            Role::Client => [
                ['code' => 'brief_quality', 'weight' => 0.25],
                ['code' => 'responsiveness', 'weight' => 0.20],
                ['code' => 'payment_reliability', 'weight' => 0.25],
                ['code' => 'cooperation', 'weight' => 0.15],
                ['code' => 'respect', 'weight' => 0.15],
            ],
            Role::Agent => [
                ['code' => 'result_quality', 'weight' => 0.30],
                ['code' => 'expertise', 'weight' => 0.15],
                ['code' => 'communication', 'weight' => 0.15],
                ['code' => 'deadline', 'weight' => 0.20],
                ['code' => 'value_for_money', 'weight' => 0.10],
                ['code' => 'transparency', 'weight' => 0.10],
            ],
            default => [],
        };
    }

    /**
     * Valid criterion codes for the given role.
     *
     * @return list<string>
     */
    public static function codesForRole(Role $role): array
    {
        return array_column(self::forRole($role), 'code');
    }

    /**
     * Weighted deal score over the criteria that were actually rated (weights
     * renormalised); null when nothing was rated (comment-only review).
     *
     * @param  array<string, int>  $scores  code => score
     */
    public static function dealScore(Role $role, array $scores): ?float
    {
        $sum = 0.0;
        $weights = 0.0;

        foreach (self::forRole($role) as $criterion) {
            if (isset($scores[$criterion['code']])) {
                $sum += $criterion['weight'] * $scores[$criterion['code']];
                $weights += $criterion['weight'];
            }
        }

        return $weights > 0 ? round($sum / $weights, 2) : null;
    }
}
