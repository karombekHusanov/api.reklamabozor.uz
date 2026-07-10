<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AgentProfileStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\File;
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

    /**
     * @return array<string, mixed>
     */
    private function agentPayload(int $adminId): array
    {
        $passport = File::factory()->create(['uploaded_by' => $adminId]);
        $certificate = File::factory()->create(['uploaded_by' => $adminId]);

        return [
            'company_name' => 'Nova Media Group',
            'legal_form' => 'MChJ',
            'inn' => '123456789',
            'director_name' => 'Akmal Karimov',
            'director_passport' => 'AA1234567',
            'director_passport_file_id' => $passport->id,
            'registration_certificate_file_id' => $certificate->id,
            'bank_name' => 'Ipoteka Bank',
            'bank_account' => '20208000900123456789',
            'mfo' => '00440',
            'phone' => '+998901234567',
        ];
    }

    public function test_admin_can_create_an_agent(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->postJson('/api/v1/admin/agents', $this->agentPayload($admin->id), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.company_name', 'Nova Media Group')
            ->assertJsonPath('data.status', 'approved');

        $profile = AgentProfile::query()->firstWhere('company_name', 'Nova Media Group');
        $this->assertNotNull($profile);
        $this->assertNotNull($profile->approved_at);
        $this->assertSame(Role::Agent, $profile->user->role);
        $this->assertSame('+998901234567', $profile->user->phone);
        $this->assertTrue($profile->user->is_active);
    }

    public function test_agent_creation_rejects_files_not_uploaded_by_the_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;
        $someoneElse = User::factory()->create();

        $payload = $this->agentPayload($someoneElse->id);

        $this->postJson('/api/v1/admin/agents', $payload, [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['director_passport_file_id']);
    }

    public function test_agent_creation_validates_kyc_formats(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $payload = [
            ...$this->agentPayload($admin->id),
            'inn' => '12345',
            'director_passport' => 'A123',
            'mfo' => '123456',
        ];

        $this->postJson('/api/v1/admin/agents', $payload, [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['inn', 'director_passport', 'mfo']);
    }

    public function test_non_admin_cannot_create_an_agent(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/admin/agents', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
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
