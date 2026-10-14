<?php

namespace Database\Factories;

use App\Enums\AgentProfileUpgradeStatus;
use App\Models\AgentProfile;
use App\Models\AgentProfileUpgrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfileUpgrade>
 */
class AgentProfileUpgradeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_profile_id' => AgentProfile::factory()->individual()->approved(),
            'user_id' => fn (array $attributes) => AgentProfile::find($attributes['agent_profile_id'])->user_id,
            'status' => AgentProfileUpgradeStatus::Pending,
            'company_name' => fake()->company(),
            'legal_form' => 'MChJ',
            'inn' => fake()->numerify('#########'),
            'legal_address' => fake()->address(),
            'director_name' => fake()->name(),
            'director_pinfl' => fake()->numerify(str_repeat('#', 14)),
            'director_position' => 'Direktor',
            'director_passport' => 'AA'.fake()->numerify('#######'),
            'bank_name' => fake()->company().' Bank',
            'bank_account' => fake()->numerify(str_repeat('#', 20)),
            'mfo' => fake()->numerify('#####'),
            'phone' => '+99890'.fake()->numerify('#######'),
            'offer_version' => config('legal.agent_offer_version'),
            'offer_hash' => str_repeat('a', 64),
            'offer_accepted_at' => now(),
        ];
    }
}
