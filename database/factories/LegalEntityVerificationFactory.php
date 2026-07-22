<?php

namespace Database\Factories;

use App\Enums\LegalEntityStatus;
use App\Models\LegalEntityVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntityVerification>
 */
class LegalEntityVerificationFactory extends Factory
{
    protected $model = LegalEntityVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'inn' => fake()->numerify('#########'),
            'company_name' => fake()->company(),
            'registration_certificate_file_id' => null,
            'status' => LegalEntityStatus::Pending,
            'rejection_reason' => null,
            'verified_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LegalEntityStatus::Approved,
            'verified_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Invalid registration document.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LegalEntityStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }
}
