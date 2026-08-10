<?php

namespace Database\Factories;

use App\Models\Hashtag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Hashtag>
 */
class HashtagFactory extends Factory
{
    protected $model = Hashtag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->word();

        return [
            'slug' => Str::slug($label) ?: 'tag-'.fake()->unique()->numerify('###'),
            'label' => $label,
            'usage_count' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
