<?php

namespace Tests\Feature\Api\V1\Chat;

use App\Models\GlobalChatBan;
use App\Models\GlobalChatMessage;
use App\Models\GlobalChatRule;
use App\Models\GlobalChatSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function client(): array
    {
        $user = User::factory()->create();

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/chat/global/messages')->assertUnauthorized();
        $this->postJson('/api/v1/chat/global/messages', ['body' => 'hi'])->assertUnauthorized();
    }

    public function test_user_can_post_and_read_messages(): void
    {
        [, $headers] = $this->client();

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'Salom hammaga!'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.body', 'Salom hammaga!');

        $this->getJson('/api/v1/chat/global/messages', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Salom hammaga!')
            ->assertJsonPath('data.0.sender.role', 'client');
    }

    public function test_deleted_messages_are_hidden_from_the_feed(): void
    {
        [, $headers] = $this->client();
        GlobalChatMessage::factory()->create(['body' => 'visible']);
        GlobalChatMessage::factory()->deleted()->create(['body' => 'removed']);

        $this->getJson('/api/v1/chat/global/messages', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'visible');
    }

    public function test_after_id_returns_only_newer_messages(): void
    {
        [, $headers] = $this->client();
        $first = GlobalChatMessage::factory()->create();
        $second = GlobalChatMessage::factory()->create();

        $this->getJson("/api/v1/chat/global/messages?after_id={$first->id}", $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id);
    }

    public function test_role_cooldown_blocks_a_second_message(): void
    {
        [$user, $headers] = $this->client();
        GlobalChatRule::factory()->forRole('client', 3600)->create();

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'first'], $headers)->assertCreated();
        $this->postJson('/api/v1/chat/global/messages', ['body' => 'second'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        // The meta endpoint exposes when the user may write again.
        $meta = $this->getJson('/api/v1/chat/global', $headers)->assertOk()->json('data');
        $this->assertSame(3600, $meta['me']['cooldown_seconds']);
        $this->assertNotNull($meta['me']['next_allowed_at']);
    }

    public function test_user_override_beats_role_rule(): void
    {
        [$user, $headers] = $this->client();
        GlobalChatRule::factory()->forRole('client', 86400)->create();
        GlobalChatRule::factory()->forUser($user, 1)->create();

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'first'], $headers)->assertCreated();

        // 1-second personal cooldown instead of the role's 24h.
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/chat/global/messages', ['body' => 'second'], $headers)->assertCreated();
    }

    public function test_banned_user_cannot_post(): void
    {
        [$user, $headers] = $this->client();
        GlobalChatBan::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'hi'], $headers)
            ->assertUnprocessable();

        $meta = $this->getJson('/api/v1/chat/global', $headers)->json('data');
        $this->assertTrue($meta['me']['banned']);
    }

    public function test_expired_ban_no_longer_blocks(): void
    {
        [$user, $headers] = $this->client();
        GlobalChatBan::factory()->expired()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'back again'], $headers)
            ->assertCreated();
    }

    public function test_disabled_chat_rejects_messages(): void
    {
        [, $headers] = $this->client();
        GlobalChatSetting::current()->update(['enabled' => false]);

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'hi'], $headers)
            ->assertUnprocessable();
    }

    public function test_message_length_limit_is_enforced(): void
    {
        [, $headers] = $this->client();
        GlobalChatSetting::current()->update(['max_message_length' => 10]);

        $this->postJson('/api/v1/chat/global/messages', ['body' => 'this is way too long'], $headers)
            ->assertUnprocessable();
    }

    public function test_pinned_message_appears_in_meta(): void
    {
        [, $headers] = $this->client();
        GlobalChatSetting::current()->update(['pinned_message' => 'Diqqat: yangi qoidalar!']);

        $this->getJson('/api/v1/chat/global', $headers)
            ->assertOk()
            ->assertJsonPath('data.pinned_message', 'Diqqat: yangi qoidalar!');
    }
}
