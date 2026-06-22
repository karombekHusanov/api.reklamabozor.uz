<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AgentProfileStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentProfileTest extends TestCase
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

    public function test_admin_can_list_agent_profiles(): void
    {
        AgentProfile::factory()->count(2)->create();
        AgentProfile::factory()->approved()->create();

        $this->getJson('/api/v1/admin/agents', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonCount(3, 'data.items');
    }

    public function test_admin_can_filter_pending_applications(): void
    {
        AgentProfile::factory()->count(2)->create(['status' => AgentProfileStatus::Pending]);
        AgentProfile::factory()->approved()->create();

        $this->getJson('/api/v1/admin/agents?status=pending', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.items.0.status', 'pending');
    }

    public function test_admin_can_approve_pending_application(): void
    {
        $user = User::factory()->create(['role' => Role::Client]);
        $profile = AgentProfile::factory()->for($user)->create();

        $this->patchJson("/api/v1/admin/agents/{$profile->id}/status", [
            'status' => 'approved',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'agent',
        ]);
        $this->assertNotNull($profile->fresh()->approved_at);
    }

    public function test_admin_can_reject_pending_application_with_reason(): void
    {
        $profile = AgentProfile::factory()->create();

        $this->patchJson("/api/v1/admin/agents/{$profile->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'Incomplete portfolio.',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Incomplete portfolio.');
    }

    public function test_reject_requires_reason(): void
    {
        $profile = AgentProfile::factory()->create();

        $this->patchJson("/api/v1/admin/agents/{$profile->id}/status", [
            'status' => 'rejected',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason']);
    }

    public function test_non_admin_cannot_manage_agents(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/agents', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }
}
