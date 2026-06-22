<?php

namespace Database\Factories;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    protected $model = AgentProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_name' => fake()->company(),
            'legal_form' => fake()->randomElement(['YaTT', 'MChJ', 'AJ']),
            'company_logo_file_id' => null,
            'director_name' => fake()->name(),
            'inn' => fake()->numerify('#########'),
            'director_passport' => strtoupper(fake()->lexify('??')).fake()->numerify('#######'),
            'director_passport_file_id' => null,
            'registration_certificate_file_id' => null,
            'bank_name' => fake()->company().' Bank',
            'bank_account' => fake()->numerify(str_repeat('#', 20)),
            'mfo' => fake()->numerify('#####'),
            'bio' => fake()->paragraph(),
            'linkedin_url' => null,
            'website_url' => fake()->url(),
            'phone' => fake()->numerify('+99890#######'),
            'lat' => fake()->latitude(),
            'lng' => fake()->longitude(),
            'location_label' => fake()->city(),
            'results_text' => fake()->sentence(),
            'status' => AgentProfileStatus::Pending,
            'rejection_reason' => null,
            'approved_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentProfileStatus::Approved,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function rejected(string $reason = 'Incomplete company information.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentProfileStatus::Rejected,
            'rejection_reason' => $reason,
            'approved_at' => null,
        ]);
    }
}
