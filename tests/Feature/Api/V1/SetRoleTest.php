<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_role_requires_authentication(): void
    {
        $this->patchJson('/api/v1/me/role', ['role' => 'agent'])->assertUnauthorized();
    }

    public function test_user_can_select_role_once(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'role_selected_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'agent',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.role', 'agent')
            ->assertJsonPath('data.id', $user->id);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'agent']);
        $this->assertNotNull($user->fresh()->role_selected_at);
    }

    public function test_role_cannot_be_changed_once_selected(): void
    {
        $user = User::factory()->create([
            'role' => Role::Designer,
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'seller',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();

        $this->assertSame(Role::Designer, $user->fresh()->role);
    }

    public function test_admin_role_cannot_be_self_selected(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'role_selected_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'admin',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $user = User::factory()->create(['role_selected_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'wizard',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }
}
