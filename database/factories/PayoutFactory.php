<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'agent_profile_id' => AgentProfile::factory(),
            'agent_id' => User::factory(),
            'tranche' => PayoutTranche::Advance,
            'amount' => fake()->numberBetween(200_000, 20_000_000),
            'currency' => 'UZS',
            'status' => PayoutStatus::Pending,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Paid,
            'method' => 'manual',
            'paid_at' => now(),
        ]);
    }
}
