<?php

namespace Database\Factories;

use App\Models\GlobalChatRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GlobalChatRule>
 */
class GlobalChatRuleFactory extends Factory
{
    protected $model = GlobalChatRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => 'client',
            'user_id' => null,
            'cooldown_seconds' => 3600,
        ];
    }

    public function forRole(string $role, int $cooldownSeconds): static
    {
        return $this->state(fn () => ['role' => $role, 'user_id' => null, 'cooldown_seconds' => $cooldownSeconds]);
    }

    public function forUser(User $user, int $cooldownSeconds): static
    {
        return $this->state(fn () => ['role' => null, 'user_id' => $user->id, 'cooldown_seconds' => $cooldownSeconds]);
    }
}
