<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        return $admin->createToken('test')->plainTextToken;
    }

    public function test_admin_can_list_users_by_role(): void
    {
        User::factory()->count(2)->create(['role' => Role::Client]);
        User::factory()->agent()->create();

        $response = $this->getJson('/api/v1/admin/users?role=client', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_admin_can_search_users(): void
    {
        User::factory()->create([
            'role' => Role::Client,
            'first_name' => 'Jasur',
        ]);
        User::factory()->create([
            'role' => Role::Client,
            'first_name' => 'Other',
        ]);

        $this->getJson('/api/v1/admin/users?role=client&search=jasur', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.first_name', 'Jasur');
    }

    public function test_non_admin_cannot_access_user_admin_routes(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/users?role=client', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_unauthenticated_user_gets_401(): void
    {
        $this->getJson('/api/v1/admin/users?role=client')->assertUnauthorized();
    }

    public function test_admin_can_show_user(): void
    {
        $user = User::factory()->create(['role' => Role::Client]);

        $this->getJson("/api/v1/admin/users/{$user->id}", [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_admin_can_update_user(): void
    {
        $user = User::factory()->create([
            'role' => Role::Client,
            'first_name' => 'Old',
        ]);

        $this->patchJson("/api/v1/admin/users/{$user->id}", [
            'first_name' => 'New',
            'phone' => '+998901112233',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'New')
            ->assertJsonPath('data.phone', '+998901112233');
    }

    public function test_admin_can_toggle_user_active_state(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'is_active' => true]);

        $this->patchJson("/api/v1/admin/users/{$user->id}/active", [
            'is_active' => false,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $this->patchJson("/api/v1/admin/users/{$admin->id}/active", [
            'is_active' => false,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_list_requires_role(): void
    {
        $this->getJson('/api/v1/admin/users', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }
}
