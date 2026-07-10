<?php

namespace Database\Factories;

use App\Models\GlobalChatBan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GlobalChatBan>
 */
class GlobalChatBanFactory extends Factory
{
    protected $model = GlobalChatBan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'banned_by' => User::factory()->admin(),
            'reason' => fake()->sentence(3),
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    public function temporary(int $hours = 24): static
    {
        return $this->state(fn () => ['expires_at' => now()->addHours($hours)]);
    }
}
