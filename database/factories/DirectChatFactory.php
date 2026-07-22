<?php

namespace Database\Factories;

use App\Models\AgentProfile;
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

    /**
     * Link the chat to the agent's provider profile when one exists and the
     * caller did not set it explicitly (mirrors the production write path).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (DirectChat $chat): void {
            if ($chat->agent_profile_id === null) {
                $profileId = AgentProfile::query()->where('user_id', $chat->agent_id)->value('id');

                if ($profileId !== null) {
                    $chat->forceFill(['agent_profile_id' => $profileId])->saveQuietly();
                }
            }
        });
    }
}
