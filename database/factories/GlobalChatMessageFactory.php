<?php

namespace Database\Factories;

use App\Models\GlobalChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GlobalChatMessage>
 */
class GlobalChatMessageFactory extends Factory
{
    protected $model = GlobalChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function deleted(): static
    {
        return $this->state(fn () => [
            'deleted_at' => now(),
            'deleted_by' => User::factory()->admin(),
        ]);
    }
}
