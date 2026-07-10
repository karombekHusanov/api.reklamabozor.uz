<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_telegram_user_is_created_as_client(): void
    {
        $response = $this->postJson('/api/v1/auth/telegram', [
            'telegram_id' => 987654321,
            'phone' => '+998901234567',
            'first_name' => 'Ali',
            'last_name' => 'Valiyev',
            'username' => 'ali_valiyev',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.telegram_id', 987654321)
            ->assertJsonPath('data.user.first_name', 'Ali')
            ->assertJsonPath('data.user.last_name', 'Valiyev')
            ->assertJsonPath('data.user.username', 'ali_valiyev')
            ->assertJsonPath('data.user.phone', '+998901234567')
            ->assertJsonPath('data.user.role', 'client')
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'telegram_id', 'first_name', 'role'],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'telegram_id' => 987654321,
            'role' => 'client',
            'is_active' => true,
        ]);
    }

    public function test_returning_telegram_user_is_updated_without_resetting_role(): void
    {
        $user = User::factory()->admin()->create([
            'telegram_id' => 111222333,
            'first_name' => 'Old',
            'last_name' => 'Name',
            'username' => 'old_username',
        ]);

        $response = $this->postJson('/api/v1/auth/telegram', [
            'telegram_id' => 111222333,
            'phone' => '+998900000000',
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'username' => 'new_username',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.first_name', 'Updated')
            ->assertJsonPath('data.user.username', 'new_username')
            ->assertJsonPath('data.user.role', 'admin');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_telegram_login_requires_telegram_id_and_first_name(): void
    {
        $this->postJson('/api/v1/auth/telegram', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telegram_id', 'first_name']);
    }

    public function test_telegram_login_does_not_wipe_an_existing_phone(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 424242,
            'phone' => '+998901112233',
        ]);

        // Mini app login omits phone — it must NOT be cleared.
        $this->postJson('/api/v1/auth/telegram', [
            'telegram_id' => 424242,
            'first_name' => 'Dilshod',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.phone', '+998901112233');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => '+998901112233',
        ]);
    }

    public function test_admin_can_login_with_email_and_password(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/v1/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@example.com')
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'role']]]);
    }

    public function test_non_admin_user_cannot_use_admin_login(): void
    {
        User::factory()->create([
            'email' => 'client@example.com',
            'password' => 'secret-password',
            'role' => Role::Client,
        ]);

        $this->postJson('/api/v1/auth/admin/login', [
            'email' => 'client@example.com',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_login_rejects_wrong_password(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/v1/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_login_rejects_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/admin/login', [
            'email' => 'nobody@example.com',
            'password' => 'any-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_inactive_admin_cannot_login(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/auth/admin/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_authenticated_user_can_logout_and_token_is_revoked(): void
    {
        $authResponse = $this->postJson('/api/v1/auth/telegram', [
            'telegram_id' => 555666777,
            'first_name' => 'Logout',
            'username' => 'logout_test',
        ]);

        $token = $authResponse->json('data.token');
        $userId = $authResponse->json('data.user.id');

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $userId,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 111000,
            'first_name' => 'Me',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.first_name', 'Me');
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_login_links_admin_created_agent_by_phone(): void
    {
        // Phone was captured earlier; the manager created the agency afterwards.
        $telegramUser = User::factory()->create([
            'telegram_id' => 424242,
            'phone' => '+998907771122',
            'role' => Role::Client,
        ]);

        $placeholder = User::factory()->create([
            'telegram_id' => null,
            'phone' => '+998907771122',
            'role' => Role::Agent,
        ]);
        $profile = AgentProfile::factory()->approved()->create(['user_id' => $placeholder->id]);

        $this->postJson('/api/v1/auth/telegram', [
            'telegram_id' => 424242,
            'first_name' => 'Akmal',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'agent');

        $this->assertSame($telegramUser->id, $profile->refresh()->user_id);
        $this->assertDatabaseMissing('users', ['id' => $placeholder->id]);
    }
}
