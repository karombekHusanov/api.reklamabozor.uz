<?php

namespace Tests\Feature\Api\V1\Chat;

use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\File;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An in-progress order with its chat between client and winning agent.
     *
     * @return array{0: Order, 1: Chat, 2: User, 3: User} [order, chat, client, agent]
     */
    private function deal(): array
    {
        $client = User::factory()->create(['telegram_id' => 111222333]);
        $agent = User::factory()->create(['telegram_id' => 444555666]);
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        $chat = Chat::factory()->forDeal($order, $agent)->create();

        return [$order, $chat, $client, $agent];
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_accepting_an_offer_opens_the_chat(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create();

        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertOk();

        $this->assertDatabaseHas('chats', [
            'order_id' => $order->id,
            'client_id' => $client->id,
            'agent_id' => $offer->agent_id,
        ]);
    }

    public function test_participants_can_read_the_chat(): void
    {
        [$order, $chat, $client, $agent] = $this->deal();
        ChatMessage::factory()->for($chat)->create(['sender_id' => $agent->id, 'body' => 'Salom!']);

        $this->getJson("/api/v1/orders/{$order->id}/chat", [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonPath('data.chat.order_id', $order->id)
            ->assertJsonPath('data.messages.0.body', 'Salom!');
    }

    public function test_strangers_cannot_access_the_chat(): void
    {
        [$order] = $this->deal();
        $stranger = User::factory()->create();

        $this->getJson("/api/v1/orders/{$order->id}/chat", [
            'Authorization' => 'Bearer '.$this->token($stranger),
        ])->assertNotFound();

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['body' => 'hi'], [
            'Authorization' => 'Bearer '.$this->token($stranger),
        ])->assertNotFound();
    }

    public function test_participant_can_send_a_message_and_recipient_gets_one_ping(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        [$order, , $client] = $this->deal();

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['body' => 'Ish qalay?'], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Ish qalay?');

        // Agent (recipient) is pinged with a deep link to the chat.
        Http::assertSent(function ($request) use ($order) {
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return ($request['chat_id'] ?? null) === 444555666
                && str_contains($request['text'] ?? '', "#{$order->id}")
                && $url === "https://app.test/chat/{$order->id}";
        });

        // A second message while the first is still unread must NOT ping again.
        Http::fake();
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['body' => 'Yana bir gap'], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertCreated();
        Http::assertNothingSent();
    }

    public function test_participant_can_attach_files(): void
    {
        Http::fake();
        [$order, , $client] = $this->deal();
        $files = File::factory()->count(2)->create(['uploaded_by' => $client->id]);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'TZ',
            'file_ids' => [$files[0]->id, $files[1]->id],
        ], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'TZ')
            ->assertJsonCount(2, 'data.attachments')
            ->assertJsonPath('data.attachments.0.id', $files[0]->id)
            ->assertJsonPath('data.attachments.1.id', $files[1]->id);

        // The whole batch is one message.
        $this->assertSame(1, ChatMessage::count());
    }

    public function test_cannot_attach_another_users_file(): void
    {
        Http::fake();
        [$order, , $client] = $this->deal();
        $file = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['file_ids' => [$file->id]], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file_ids');
    }

    public function test_reading_marks_messages_read_and_reenables_ping(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        [$order, $chat, $client, $agent] = $this->deal();
        ChatMessage::factory()->for($chat)->create(['sender_id' => $client->id]);

        // Agent opens the thread — the client's message becomes read.
        $this->getJson("/api/v1/orders/{$order->id}/chat", [
            'Authorization' => 'Bearer '.$this->token($agent),
        ])->assertOk();

        $this->assertSame(0, $chat->fresh()->unreadCountFor($agent));

        // Switch identity within the same test — drop the cached guard user,
        // otherwise the next request still authenticates as the agent.
        $this->app['auth']->forgetGuards();

        // Next client message pings again since the agent has caught up.
        Http::fake();
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['body' => 'Yangilik bor'], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertCreated();
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 444555666);
    }

    public function test_polling_returns_only_messages_after_the_given_id(): void
    {
        [$order, $chat, $client, $agent] = $this->deal();
        $first = ChatMessage::factory()->for($chat)->create(['sender_id' => $agent->id]);
        $second = ChatMessage::factory()->for($chat)->create(['sender_id' => $agent->id, 'body' => 'keyingisi']);

        $this->getJson("/api/v1/orders/{$order->id}/chat/messages?after={$first->id}", [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);
    }

    public function test_chat_is_read_only_once_the_order_is_terminal(): void
    {
        [$order, , $client] = $this->deal();
        $order->update(['status' => OrderStatus::Completed]);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['body' => 'kech qoldim'], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertUnprocessable();

        // Reading history still works.
        $this->getJson("/api/v1/orders/{$order->id}/chat", [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertOk();
    }

    public function test_user_sees_their_chat_list_with_unread_counts(): void
    {
        [, $chat, $client, $agent] = $this->deal();
        ChatMessage::factory()->for($chat)->count(2)->create(['sender_id' => $agent->id]);
        Chat::factory()->create(); // someone else's chat

        $this->getJson('/api/v1/chats', [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread_count', 2)
            ->assertJsonPath('data.0.other_participant.id', $agent->id);
    }

    public function test_chat_list_can_be_filtered_by_agent_profile(): void
    {
        [$order, $chat, $client, $agent] = $this->deal();
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        Chat::factory()->create(); // unrelated chat

        $this->getJson("/api/v1/chats?agent_profile_id={$profile->id}", [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.other_participant.agent_profile_id', $profile->id);
    }

    public function test_admin_can_read_the_transcript(): void
    {
        [$order, $chat, $client] = $this->deal();
        ChatMessage::factory()->for($chat)->create(['sender_id' => $client->id, 'body' => 'maxfiy emas']);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->getJson("/api/v1/admin/orders/{$order->id}/chat", [
            'Authorization' => 'Bearer '.$this->token($admin),
        ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'maxfiy emas');
    }
}
