<?php

namespace Database\Factories;

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
}
