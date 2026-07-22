<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'agent_id' => User::factory(),
            'price' => fake()->numberBetween(500_000, 50_000_000),
            'comment' => fake()->sentence(),
            'status' => OfferStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => OfferStatus::Accepted]);
    }

    /**
     * Link the offer to the agent's provider profile when one exists and the
     * caller did not set it explicitly (mirrors the production write path).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Offer $offer): void {
            if ($offer->agent_profile_id === null) {
                $profileId = AgentProfile::query()->where('user_id', $offer->agent_id)->value('id');

                if ($profileId !== null) {
                    $offer->forceFill(['agent_profile_id' => $profileId])->saveQuietly();
                }
            }
        });
    }
}
