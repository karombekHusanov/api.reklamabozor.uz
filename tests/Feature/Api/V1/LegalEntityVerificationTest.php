<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\LegalEntityVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalEntityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function legalClient(): User
    {
        return User::factory()->create([
            'role' => Role::Client,
            'roles' => [Role::Client],
            'person_type' => PersonType::LegalEntity,
        ]);
    }

    public function test_self_declared_legal_entity_submits_a_verification(): void
    {
        $user = $this->legalClient();

        $this->postJson('/api/v1/me/legal-entity', [
            'inn' => '123456789',
            'company_name' => 'Acme LLC',
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.inn', '123456789');

        // Submitted but not yet verified.
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertJsonPath('data.person_type', 'legal_entity')
            ->assertJsonPath('data.person_type_verified', false)
            ->assertJsonPath('data.legal_entity_status', 'pending');
    }

    public function test_individual_cannot_request_verification(): void
    {
        $user = User::factory()->create([
            'role' => Role::Client,
            'roles' => [Role::Client],
            'person_type' => PersonType::Individual,
        ]);

        $this->postJson('/api/v1/me/legal-entity', [
            'inn' => '123456789',
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable();
    }

    public function test_agent_cannot_request_verification_being_already_verified(): void
    {
        $user = User::factory()->create([
            'role' => Role::Agent,
            'roles' => [Role::Client, Role::Agent],
        ]);

        $this->postJson('/api/v1/me/legal-entity', [
            'inn' => '123456789',
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertUnprocessable();
    }

    public function test_approved_request_makes_the_user_a_verified_legal_entity(): void
    {
        $user = $this->legalClient();
        LegalEntityVerification::factory()->for($user)->approved()->create();

        $this->assertTrue($user->fresh()->isVerifiedLegalEntity());

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertJsonPath('data.person_type_verified', true)
            ->assertJsonPath('data.legal_entity_status', 'approved');
    }

    public function test_admin_approves_a_verification(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->legalClient();
        $verification = LegalEntityVerification::factory()->for($user)->create();

        $this->patchJson("/api/v1/admin/legal-entity-verifications/{$verification->id}/status", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$this->token($admin)])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTrue($user->fresh()->isVerifiedLegalEntity());
    }

    public function test_admin_rejects_a_verification_with_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->legalClient();
        $verification = LegalEntityVerification::factory()->for($user)->create();

        $this->patchJson("/api/v1/admin/legal-entity-verifications/{$verification->id}/status", [
            'status' => 'rejected',
            'rejection_reason' => 'Blurry document.',
        ], ['Authorization' => 'Bearer '.$this->token($admin)])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Blurry document.');

        $this->assertFalse($user->fresh()->isVerifiedLegalEntity());
    }
}
