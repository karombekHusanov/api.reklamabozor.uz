<?php

namespace Tests\Feature\Api\V1\Chat;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectChatTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * @return array{0: User, 1: AgentProfile, 2: User}
     */
    private function agency(): array
    {
        $client = User::factory()->create(['telegram_id' => 111222333]);
        $agentUser = User::factory()->agent()->create(['telegram_id' => 444555666]);
        $profile = AgentProfile::factory()->for($agentUser)->create([
            'status' => AgentProfileStatus::Approved,
            'company_name' => 'Nova Media',
        ]);

        return [$client, $profile, $agentUser];
    }

    public function test_client_can_open_direct_chat_from_agent_profile(): void
    {
        [$client, $profile] = $this->agency();

        $this->postJson("/api/v1/agents/{$profile->id}/direct-chat", [], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.other_participant.company_name', 'Nova Media');

        $this->assertDatabaseHas('direct_chats', [
            'client_id' => $client->id,
            'agent_id' => $profile->user_id,
        ]);
    }

    public function test_open_is_idempotent_for_same_pair(): void
    {
        [$client, $profile] = $this->agency();

        $this->postJson("/api/v1/agents/{$profile->id}/direct-chat", [], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertOk();

        $this->postJson("/api/v1/agents/{$profile->id}/direct-chat", [], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk();

        $this->assertSame(1, DirectChat::query()->count());
    }

    public function test_participants_can_exchange_messages(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();

        [$client, $profile, $agentUser] = $this->agency();
        $chat = DirectChat::factory()->between($client, $agentUser)->create();

        $this->postJson("/api/v1/direct-chats/{$chat->id}/messages", ['body' => 'Salom agent!'], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Salom agent!');

        Http::assertSent(function ($request) use ($chat) {
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return ($request['chat_id'] ?? null) === 444555666
                && $url === "https://app.test/chat/direct/{$chat->id}";
        });

        $this->getJson("/api/v1/direct-chats/{$chat->id}", [
            'Authorization' => 'Bearer '.$this->token($agentUser),
        ])
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'Salom agent!');
    }

    public function test_strangers_cannot_access_direct_chat(): void
    {
        [$client, , $agentUser] = $this->agency();
        $chat = DirectChat::factory()->between($client, $agentUser)->create();
        $stranger = User::factory()->create();

        $this->getJson("/api/v1/direct-chats/{$chat->id}", [
            'Authorization' => 'Bearer '.$this->token($stranger),
        ])->assertNotFound();
    }

    public function test_inbox_lists_direct_and_order_chats(): void
    {
        [$client, $profile, $agentUser] = $this->agency();
        DirectChat::factory()->between($client, $agentUser)->create();

        $this->getJson('/api/v1/chats', [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonPath('data.0.type', 'direct');
    }

    public function test_agent_can_open_direct_chat_with_another_agency_as_client(): void
    {
        // Multirole: every user holds the client base role, so an agency can
        // reach out to a different agency acting as a client.
        [, $profile] = $this->agency();
        $otherAgent = User::factory()->agent()->create(['telegram_id' => 777888999]);

        $this->postJson("/api/v1/agents/{$profile->id}/direct-chat", [], [
            'Authorization' => 'Bearer '.$this->token($otherAgent),
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'direct');

        $this->assertDatabaseHas('direct_chats', [
            'client_id' => $otherAgent->id,
            'agent_id' => $profile->user_id,
        ]);
    }

    public function test_agent_cannot_open_direct_chat_from_own_profile(): void
    {
        [, $profile, $agentUser] = $this->agency();

        $this->postJson("/api/v1/agents/{$profile->id}/direct-chat", [], [
            'Authorization' => 'Bearer '.$this->token($agentUser),
        ])->assertUnprocessable();
    }

    public function test_participant_can_attach_files(): void
    {
        Http::fake();
        [$client, , $agentUser] = $this->agency();
        $chat = DirectChat::factory()->between($client, $agentUser)->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $this->postJson("/api/v1/direct-chats/{$chat->id}/messages", [
            'body' => 'Fayl',
            'file_ids' => [$file->id],
        ], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.id', $file->id);
    }

    public function test_polling_marks_messages_read(): void
    {
        [$client, , $agentUser] = $this->agency();
        $chat = DirectChat::factory()->between($client, $agentUser)->create();
        $message = DirectChatMessage::factory()->forChat($chat)->create([
            'sender_id' => $client->id,
            'read_at' => null,
        ]);

        $this->getJson("/api/v1/direct-chats/{$chat->id}/messages", [
            'Authorization' => 'Bearer '.$this->token($agentUser),
        ])->assertOk();

        $this->assertNotNull($message->fresh()->read_at);
    }
}
