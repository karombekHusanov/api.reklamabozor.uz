<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonTypeTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_client_declares_a_person_type(): void
    {
        $user = User::factory()->create(['role' => Role::Client, 'roles' => [Role::Client]]);

        $this->patchJson('/api/v1/me/person-type', [
            'person_type' => 'legal_entity',
        ], ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonPath('data.person_type', 'legal_entity')
            // Self-declared legal is NOT verified until the Phase-2 flow.
            ->assertJsonPath('data.person_type_verified', false)
            ->assertJsonPath('data.person_type_declared', 'legal_entity');
    }

    public function test_agent_is_a_verified_legal_entity_regardless_of_declaration(): void
    {
        // Even with an individual self-declaration, the agent role forces legal.
        $user = User::factory()->create([
            'role' => Role::Agent,
            'roles' => [Role::Client, Role::Agent],
            'person_type' => PersonType::Individual,
        ]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertOk()
            ->assertJsonPath('data.person_type', 'legal_entity')
            ->assertJsonPath('data.person_type_verified', true);
    }

    public function test_seller_is_a_verified_legal_entity(): void
    {
        $user = User::factory()->create(['role' => Role::Seller, 'roles' => [Role::Client, Role::Seller]]);

        $this->assertSame(PersonType::LegalEntity, $user->effectivePersonType());
        $this->assertTrue($user->isVerifiedLegalEntity());
    }

    public function test_individual_client_who_becomes_agent_reads_as_legal_entity(): void
    {
        $user = User::factory()->create([
            'role' => Role::Client,
            'roles' => [Role::Client],
            'person_type' => PersonType::Individual,
        ]);

        $this->assertSame(PersonType::Individual, $user->effectivePersonType());
        $this->assertFalse($user->isVerifiedLegalEntity());

        // Acquires the agent role (e.g. KYC approved).
        $user->grantRole(Role::Agent);
        $user->role = Role::Agent;
        $user->save();

        $this->assertSame(PersonType::LegalEntity, $user->fresh()->effectivePersonType());
        $this->assertTrue($user->fresh()->isVerifiedLegalEntity());
    }

    public function test_effective_type_reverts_to_declaration_when_role_bound_status_is_lost(): void
    {
        // An agent (legal) who declared individual, then loses the agent role
        // (e.g. admin moves them to designer): effective type reverts, no stale.
        $user = User::factory()->create([
            'role' => Role::Agent,
            'roles' => [Role::Client, Role::Agent],
            'person_type' => PersonType::Individual,
        ]);
        $this->assertSame(PersonType::LegalEntity, $user->effectivePersonType());

        $user->revokeRole(Role::Agent);
        $user->grantRole(Role::Designer);
        $user->role = Role::Designer;
        $user->save();

        $this->assertSame(PersonType::Individual, $user->fresh()->effectivePersonType());
        $this->assertFalse($user->fresh()->isVerifiedLegalEntity());
    }
}
