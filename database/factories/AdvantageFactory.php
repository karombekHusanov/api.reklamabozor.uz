<?php

namespace Database\Factories;

use App\Models\Advantage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advantage>
 */
class AdvantageFactory extends Factory
{
    protected $model = Advantage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_uz' => fake()->words(2, true),
            'name_ru' => fake()->words(2, true),
            'hint_uz' => fake()->sentence(4),
            'hint_ru' => fake()->sentence(4),
            'icon' => 'sparkles',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
