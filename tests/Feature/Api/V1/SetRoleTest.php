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

    public function test_user_selects_first_role_during_onboarding(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'role_selected_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'agent',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.role', 'agent')
            // Client is the base role and is retained; agent is added on top.
            ->assertJsonPath('data.roles', ['client', 'agent'])
            ->assertJsonPath('data.id', $user->id);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'agent']);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->role_selected_at);
        $this->assertTrue($fresh->hasRole(Role::Client));
    }

    public function test_user_can_acquire_an_additional_role(): void
    {
        $user = User::factory()->create([
            'role' => Role::Client,
            'roles' => [Role::Client],
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'designer',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.role', 'designer')
            ->assertJsonPath('data.roles', ['client', 'designer']);

        $fresh = $user->fresh();
        $this->assertSame(Role::Designer, $fresh->role);
        $this->assertTrue($fresh->hasRole(Role::Client));
    }

    public function test_user_can_switch_back_to_a_held_role(): void
    {
        $user = User::factory()->create([
            'role' => Role::Designer,
            'roles' => [Role::Client, Role::Designer],
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'client',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.roles', ['client', 'designer']);

        $this->assertSame(Role::Client, $user->fresh()->role);
    }

    public function test_legacy_user_without_roles_column_keeps_held_role_when_switching(): void
    {
        // Rows written before the multirole migration have roles = null; the
        // model normalizes the held set from the committed active role.
        $user = User::factory()->create([
            'role' => Role::Seller,
            'roles' => null,
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'client',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.roles', ['seller', 'client']);
    }

    public function test_designer_cannot_also_become_an_agent(): void
    {
        // Designer is a solo provider role — no agent/seller alongside it.
        $user = User::factory()->create([
            'role' => Role::Designer,
            'roles' => [Role::Client, Role::Designer],
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'agent',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertFalse($user->fresh()->hasRole(Role::Agent));
    }

    public function test_agent_can_also_become_a_seller(): void
    {
        // Agent + seller form the business group and may coexist.
        $user = User::factory()->create([
            'role' => Role::Agent,
            'roles' => [Role::Client, Role::Agent],
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'seller',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.role', 'seller')
            ->assertJsonPath('data.roles', ['client', 'agent', 'seller']);
    }

    public function test_agent_cannot_also_become_a_designer(): void
    {
        $user = User::factory()->create([
            'role' => Role::Agent,
            'roles' => [Role::Client, Role::Agent],
            'role_selected_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me/role', [
            'role' => 'designer',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
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
