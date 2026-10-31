<?php

namespace Database\Factories;

use App\Enums\AdPlacementKind;
use App\Enums\AdPlacementStatus;
use App\Models\AdPlacement;
use App\Models\AgentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdPlacement>
 */
class AdPlacementFactory extends Factory
{
    protected $model = AdPlacement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => AdPlacementKind::LedScreen,
            'status' => AdPlacementStatus::Unclaimed,
            'owner_name_raw' => fake()->company(),
            'owner_kind' => 'legal',
            'external_ref' => (string) fake()->numberBetween(1, 99),
            'address' => fake()->streetAddress(),
            // Inside Tashkent.
            'lat' => fake()->randomFloat(6, 41.22, 41.36),
            'lng' => fake()->randomFloat(6, 69.16, 69.38),
            'source' => 'import',
            'imported_at' => now(),
        ];
    }

    public function ownedBy(AgentProfile $profile): static
    {
        return $this->state(fn () => [
            'owner_agent_profile_id' => $profile->id,
            'owner_name_raw' => $profile->company_name ?? 'Agency',
            'status' => AdPlacementStatus::Active,
            'claimed_at' => now(),
        ]);
    }

    public function status(AdPlacementStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
