<?php

namespace Database\Factories;

use App\Models\MxikCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MxikCode>
 */
class MxikCodeFactory extends Factory
{
    protected $model = MxikCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) $this->faker->unique()->numerify('###############'),
            'package_code' => (string) $this->faker->numerify('#######'),
            'name_uz' => $this->faker->words(3, true),
            'name_ru' => $this->faker->words(3, true),
            'vat_rate' => 12,
            'unit' => 'dona',
            'is_active' => true,
        ];
    }
}
