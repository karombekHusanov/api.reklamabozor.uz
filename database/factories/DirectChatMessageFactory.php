<?php

namespace Database\Factories;

use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectChatMessage>
 */
class DirectChatMessageFactory extends Factory
{
    protected $model = DirectChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direct_chat_id' => DirectChat::factory(),
            'sender_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function forChat(DirectChat $chat): static
    {
        return $this->state(fn (array $attributes) => [
            'direct_chat_id' => $chat->id,
        ]);
    }
}
