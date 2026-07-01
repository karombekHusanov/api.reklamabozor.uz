<?php

namespace Database\Factories;

use App\Enums\BannerType;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    protected $model = Banner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'subtitle' => fake()->sentence(3),
            'type' => BannerType::Agent,
            'target_id' => fake()->numberBetween(1, 50),
            'image_file_id' => null,
            'link_url' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function product(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => BannerType::Product,
        ]);
    }
}
