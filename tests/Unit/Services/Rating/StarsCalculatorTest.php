<?php

namespace Tests\Unit\Services\Rating;

use App\Enums\ReviewDirection;
use App\Enums\Role;
use App\Models\Review;
use App\Models\User;
use App\Services\Rating\StarsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StarsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private StarsCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new StarsCalculator;
    }

    public function test_no_reviews_returns_five_stars(): void
    {
        $user = User::factory()->create();
        $result = $this->calc->compute($user->id, Role::Agent);

        $this->assertEquals(5.0, $result['stars']);
        $this->assertEquals(0, $result['count']);
    }

    public function test_single_low_review_barely_moves_stars(): void
    {
        $user = User::factory()->create();

        Review::factory()->approved()->create([
            'direction' => ReviewDirection::ClientToProvider,
            'reviewee_id' => $user->id,
            'rating' => 3.00,
        ]);

        $result = $this->calc->compute($user->id, Role::Agent);

        // R = (50*5 + 3*1)/(50+1) = 253/51 ≈ 4.96 → rounds to 5.0
        $this->assertGreaterThan(4.9, $result['stars']);
        $this->assertEquals(1, $result['count']);
    }

    public function test_many_low_reviews_pull_stars_down(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            Review::factory()->approved()->create([
                'direction' => ReviewDirection::ClientToProvider,
                'reviewee_id' => $user->id,
                'rating' => 2.00,
            ]);
        }

        $result = $this->calc->compute($user->id, Role::Agent);

        $this->assertLessThan(5.0, $result['stars']);
        $this->assertEquals(20, $result['count']);
    }

    public function test_client_uses_provider_to_client_reviews(): void
    {
        $user = User::factory()->create();

        Review::factory()->approved()->create([
            'direction' => ReviewDirection::ProviderToClient,
            'reviewee_id' => $user->id,
            'rating' => 4.00,
        ]);

        // This should NOT count for client role
        Review::factory()->approved()->create([
            'direction' => ReviewDirection::ClientToProvider,
            'reviewee_id' => $user->id,
            'rating' => 1.00,
        ]);

        $result = $this->calc->compute($user->id, Role::Client);

        $this->assertEquals(1, $result['count']);
    }

    public function test_listing_boost_brackets(): void
    {
        $this->assertEquals(4, StarsCalculator::listingBoost(5.0));
        $this->assertEquals(4, StarsCalculator::listingBoost(4.8));
        $this->assertEquals(0, StarsCalculator::listingBoost(4.7));
        $this->assertEquals(0, StarsCalculator::listingBoost(4.5));
        $this->assertEquals(-4, StarsCalculator::listingBoost(4.3));
        $this->assertEquals(-8, StarsCalculator::listingBoost(4.1));
        $this->assertEquals(-8, StarsCalculator::listingBoost(3.5));
    }

    public function test_window_keeps_newest_reviews_not_oldest(): void
    {
        $user = User::factory()->create();

        // 41 reviews for client window W=40: oldest is 1.0, the rest are 5.0.
        // If we wrongly took oldest 40, average would include the 1.0 heavily;
        // taking newest 40 drops the 1.0 entirely → stars stay near 5.0.
        Review::factory()->approved()->create([
            'direction' => ReviewDirection::ProviderToClient,
            'reviewee_id' => $user->id,
            'rating' => 1.00,
            'created_at' => now()->subDays(100),
        ]);

        for ($i = 0; $i < 40; $i++) {
            Review::factory()->approved()->create([
                'direction' => ReviewDirection::ProviderToClient,
                'reviewee_id' => $user->id,
                'rating' => 5.00,
                'created_at' => now()->subDays(40 - $i),
            ]);
        }

        $result = $this->calc->compute($user->id, Role::Client);

        $this->assertEquals(40, $result['count']);
        $this->assertEquals(5.0, $result['stars']);
    }
}
