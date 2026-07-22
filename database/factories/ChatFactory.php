<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\Chat;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chat>
 */
class ChatFactory extends Factory
{
    protected $model = Chat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'client_id' => User::factory(),
            'agent_id' => User::factory(),
        ];
    }

    /**
     * Wire the chat to an order's client and the given agent.
     */
    public function forDeal(Order $order, User $agent): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->id,
            'client_id' => $order->client_id,
            'agent_id' => $agent->id,
        ]);
    }

    /**
     * Link the chat to the agent's provider profile when one exists and the
     * caller did not set it explicitly (mirrors the production write path).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Chat $chat): void {
            if ($chat->agent_profile_id === null) {
                $profileId = AgentProfile::query()->where('user_id', $chat->agent_id)->value('id');

                if ($profileId !== null) {
                    $chat->forceFill(['agent_profile_id' => $profileId])->saveQuietly();
                }
            }
        });
    }
}
