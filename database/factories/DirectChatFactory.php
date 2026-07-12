<?php

namespace Database\Factories;

use App\Models\DirectChat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectChat>
 */
class DirectChatFactory extends Factory
{
    protected $model = DirectChat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => User::factory(),
            'agent_id' => User::factory()->agent(),
        ];
    }

    public function between(User $client, User $agent): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);
    }
}
