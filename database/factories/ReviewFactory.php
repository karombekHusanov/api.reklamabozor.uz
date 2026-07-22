<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'client_id' => User::factory(),
            'agent_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->sentence(),
            'status' => ReviewStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ReviewStatus::Approved]);
    }

    /**
     * Link the review to the agent's provider profile when one exists and the
     * caller did not set it explicitly (mirrors the production write path).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Review $review): void {
            if ($review->agent_profile_id === null) {
                $profileId = AgentProfile::query()->where('user_id', $review->agent_id)->value('id');

                if ($profileId !== null) {
                    $review->forceFill(['agent_profile_id' => $profileId])->saveQuietly();
                }
            }
        });
    }
}
