<?php

namespace Tests\Unit\Services\Rating;

use App\Enums\Role;
use App\Services\Rating\RatingCriteria;
use PHPUnit\Framework\TestCase;

class RatingCriteriaTest extends TestCase
{
    public function test_all_roles_have_criteria(): void
    {
        foreach ([Role::Client, Role::Agent, Role::Designer, Role::Seller] as $role) {
            $criteria = RatingCriteria::forRole($role);
            $this->assertNotEmpty($criteria, "No criteria for {$role->value}");
        }
    }

    public function test_weights_sum_to_one(): void
    {
        foreach ([Role::Client, Role::Agent, Role::Designer, Role::Seller] as $role) {
            $criteria = RatingCriteria::forRole($role);
            $sum = array_sum(array_column($criteria, 'weight'));
            $this->assertEqualsWithDelta(1.0, $sum, 0.001, "Weights for {$role->value} do not sum to 1.0");
        }
    }

    public function test_admin_has_no_criteria(): void
    {
        $this->assertEmpty(RatingCriteria::forRole(Role::Admin));
    }

    public function test_deal_score_computation(): void
    {
        $scores = [
            'result_quality' => 5,
            'expertise' => 5,
            'communication' => 5,
            'deadline' => 5,
            'value_for_money' => 5,
            'transparency' => 5,
        ];

        $this->assertEqualsWithDelta(5.0, RatingCriteria::dealScore(Role::Agent, $scores), 0.01);
    }

    public function test_deal_score_with_mixed_scores(): void
    {
        $scores = [
            'result_quality' => 5,
            'expertise' => 4,
            'communication' => 5,
            'deadline' => 4,
            'value_for_money' => 3,
            'transparency' => 5,
        ];

        // 5*0.3 + 4*0.15 + 5*0.15 + 4*0.2 + 3*0.1 + 5*0.1 = 1.5+0.6+0.75+0.8+0.3+0.5 = 4.45
        $this->assertEqualsWithDelta(4.45, RatingCriteria::dealScore(Role::Agent, $scores), 0.01);
    }
}
