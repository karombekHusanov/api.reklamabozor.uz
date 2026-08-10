<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\AgentContractStatus;
use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the generated contract PDFs off the real disk.
        Storage::fake((string) config('files.disk'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function kycPayload(User $user, array $overrides = []): array
    {
        $passport = File::factory()->for($user, 'uploader')->create();
        $certificate = File::factory()->for($user, 'uploader')->create();

        return [
            'company_name' => 'Nova Media Group',
            'legal_form' => 'MChJ',
            'inn' => '123456789',
            'director_name' => 'Ali Valiyev',
            'director_passport' => 'AA1234567',
            'director_passport_file_id' => $passport->id,
            'registration_certificate_file_id' => $certificate->id,
            'bank_name' => 'Ipoteka Bank',
            'bank_account' => '20208000900123456789',
            'mfo' => '00440',
            'phone' => '+998901234567',
            ...$overrides,
        ];
    }

    public function test_kyc_submission_generates_a_contract_awaiting_signature(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/agent/profile', $this->kycPayload($user))
            ->assertCreated()
            ->assertJsonPath('data.contract.status', 'awaiting_signature')
            ->assertJsonPath('data.contract.approved', false);

        $profile = $user->agentProfile()->first();
        $this->assertNotNull($profile->contract_file_id);
        $this->assertNotNull($profile->contract_hash);
        $this->assertSame(AgentContractStatus::AwaitingSignature, $profile->contract_status);
    }

    public function test_agent_uploads_signed_contract_moves_to_under_review(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->create([
            'contract_status' => AgentContractStatus::AwaitingSignature,
        ]);
        $signed = File::factory()->for($user, 'uploader')->create();

        $this->actingAs($user)->postJson('/api/v1/agent/profile/contract', ['file_id' => $signed->id])
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'under_review');

        $this->assertSame(AgentContractStatus::UnderReview, $profile->fresh()->contract_status);
        $this->assertSame($signed->id, $profile->fresh()->signed_contract_file_id);
    }

    public function test_cannot_upload_someone_elses_file_as_signed_contract(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->create([
            'contract_status' => AgentContractStatus::AwaitingSignature,
        ]);
        $foreign = File::factory()->for(User::factory(), 'uploader')->create();

        $this->actingAs($user)->postJson('/api/v1/agent/profile/contract', ['file_id' => $foreign->id])
            ->assertUnprocessable();
    }

    public function test_approval_blocked_until_signed_contract_uploaded(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = AgentProfile::factory()->create([
            'contract_status' => AgentContractStatus::AwaitingSignature,
        ]);

        $this->actingAs($admin)->patchJson("/api/v1/admin/agents/{$profile->id}/status", [
            'status' => 'approved',
        ])->assertUnprocessable();

        $this->assertSame(AgentProfileStatus::Pending, $profile->fresh()->status);
    }

    public function test_approval_succeeds_with_signed_contract_and_approves_it(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = AgentProfile::factory()->contractUnderReview()->create();

        $this->actingAs($admin)->patchJson("/api/v1/admin/agents/{$profile->id}/status", [
            'status' => 'approved',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.contract.status', 'approved');
    }

    public function test_manager_can_reject_signed_contract_without_rejecting_kyc(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = AgentProfile::factory()->contractUnderReview()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/agents/{$profile->id}/contract/reject", [
            'reason' => 'Muhr ko\'rinmayapti, qayta yuklang.',
        ])
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'rejected')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(AgentProfileStatus::Pending, $profile->fresh()->status);
    }

    public function test_designer_profile_requires_no_contract(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->designer()->approved()->for($user)->create();

        $this->assertFalse($profile->requiresContract());
        $this->assertTrue($profile->contractApproved());
    }
}
