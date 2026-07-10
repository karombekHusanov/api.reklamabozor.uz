<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\GlobalChatBan;
use App\Models\GlobalChatMessage;
use App\Models\GlobalChatRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalChatAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $admin = User::factory()->admin()->create();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    public function test_moderation_requires_admin(): void
    {
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->getJson('/api/v1/admin/global-chat/messages', $headers)->assertForbidden();
        $this->getJson('/api/v1/admin/global-chat/rules', $headers)->assertForbidden();
    }

    public function test_admin_sees_deleted_messages_when_requested(): void
    {
        $headers = $this->adminHeaders();
        GlobalChatMessage::factory()->create();
        GlobalChatMessage::factory()->deleted()->create();

        $this->getJson('/api/v1/admin/global-chat/messages', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/v1/admin/global-chat/messages?include_deleted=1', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_admin_can_soft_delete_a_message(): void
    {
        $headers = $this->adminHeaders();
        $message = GlobalChatMessage::factory()->create();

        $this->deleteJson("/api/v1/admin/global-chat/messages/{$message->id}", [], $headers)
            ->assertOk();

        $this->assertNotNull($message->refresh()->deleted_at);
        $this->assertNotNull($message->deleted_by);
    }

    public function test_admin_can_set_and_clear_role_rules(): void
    {
        $headers = $this->adminHeaders();

        $this->putJson('/api/v1/admin/global-chat/rules/roles', [
            'rules' => [
                ['role' => 'client', 'cooldown_seconds' => 3600],
                ['role' => 'agent', 'cooldown_seconds' => 600],
            ],
        ], $headers)->assertOk();

        $this->assertSame(2, GlobalChatRule::query()->whereNull('user_id')->count());

        // Zero clears the rule.
        $this->putJson('/api/v1/admin/global-chat/rules/roles', [
            'rules' => [['role' => 'client', 'cooldown_seconds' => 0]],
        ], $headers)->assertOk();

        $this->assertNull(GlobalChatRule::query()->firstWhere('role', 'client'));
    }

    public function test_admin_can_manage_user_overrides(): void
    {
        $headers = $this->adminHeaders();
        $user = User::factory()->create();

        $this->postJson('/api/v1/admin/global-chat/rules/users', [
            'user_id' => $user->id,
            'cooldown_seconds' => 7200,
        ], $headers)->assertOk();

        $this->assertSame(7200, GlobalChatRule::query()->firstWhere('user_id', $user->id)->cooldown_seconds);

        $this->deleteJson("/api/v1/admin/global-chat/rules/users/{$user->id}", [], $headers)->assertOk();
        $this->assertNull(GlobalChatRule::query()->firstWhere('user_id', $user->id));
    }

    public function test_admin_can_ban_and_unban(): void
    {
        $headers = $this->adminHeaders();
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/admin/global-chat/bans', [
            'user_id' => $user->id,
            'duration_hours' => 24,
            'reason' => 'Spam',
        ], $headers)->assertCreated();

        $banId = $response->json('data.id');
        $this->assertNotNull(GlobalChatBan::query()->firstWhere('user_id', $user->id)->expires_at);

        $this->deleteJson("/api/v1/admin/global-chat/bans/{$banId}", [], $headers)->assertOk();
        $this->assertSame(0, GlobalChatBan::query()->count());
    }

    public function test_admins_cannot_be_banned(): void
    {
        $headers = $this->adminHeaders();
        $otherAdmin = User::factory()->admin()->create();

        $this->postJson('/api/v1/admin/global-chat/bans', [
            'user_id' => $otherAdmin->id,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_rebanning_replaces_the_previous_ban(): void
    {
        $headers = $this->adminHeaders();
        $user = User::factory()->create();

        $this->postJson('/api/v1/admin/global-chat/bans', [
            'user_id' => $user->id,
            'duration_hours' => 1,
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/admin/global-chat/bans', [
            'user_id' => $user->id,
        ], $headers)->assertCreated();

        $bans = GlobalChatBan::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $bans);
        $this->assertNull($bans->first()->expires_at); // permanent
    }

    public function test_admin_can_update_settings_and_pin_announcement(): void
    {
        $headers = $this->adminHeaders();

        $this->putJson('/api/v1/admin/global-chat/settings', [
            'enabled' => false,
            'max_message_length' => 300,
            'pinned_message' => 'Yangi qoidalar kuchga kirdi',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.max_message_length', 300)
            ->assertJsonPath('data.pinned_message', 'Yangi qoidalar kuchga kirdi');

        // Clearing the pin also clears its metadata.
        $this->putJson('/api/v1/admin/global-chat/settings', [
            'pinned_message' => null,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.pinned_message', null)
            ->assertJsonPath('data.pinned_at', null);
    }
}
