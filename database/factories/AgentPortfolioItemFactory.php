<?php

namespace Database\Factories;

use App\Models\AgentPortfolioItem;
use App\Models\AgentProfile;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPortfolioItem>
 */
class AgentPortfolioItemFactory extends Factory
{
    protected $model = AgentPortfolioItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_profile_id' => AgentProfile::factory(),
            'image_file_id' => File::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(8),
            'link_url' => null,
            'sort_order' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AgentPortfolioItem $item): void {
            $item->imageFiles()->attach($item->image_file_id, ['sort_order' => 0]);
        });
    }

    public function hidden(): static
    {
        return $this->state(['hidden_at' => now()]);
    }
}
