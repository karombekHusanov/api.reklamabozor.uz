<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\File;
use App\Models\User;
use App\Services\Legal\PublicOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('files.disk'));
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function kycPayload(User $user, array $overrides = []): array
    {
        return [
            'company_name' => 'Nova Media Group',
            'legal_form' => 'MChJ',
            'inn' => '123456789',
            'director_name' => 'Ali Valiyev',
            'director_passport' => 'AA1234567',
            'director_passport_file_id' => File::factory()->for($user, 'uploader')->create()->id,
            'registration_certificate_file_id' => File::factory()->for($user, 'uploader')->create()->id,
            'bank_name' => 'Ipoteka Bank',
            'bank_account' => '20208000900123456789',
            'mfo' => '00440',
            'phone' => '+998901234567',
            'accept_offer' => true,
            ...$overrides,
        ];
    }

    public function test_agent_offer_text_is_public(): void
    {
        $this->getJson('/api/v1/legal/agent-offer')
            ->assertOk()
            ->assertJsonPath('data.version', config('legal.agent_offer_version'))
            ->assertJsonCount(12, 'data.sections')
            ->assertJsonPath('data.requisites.0.value', '«Imprint Business» МЧЖ')
            ->assertJsonPath('data.pdf_url', url('/api/v1/legal/agent-offer.pdf'))
            ->assertJsonStructure(['data' => ['title', 'sections' => [['title', 'clauses' => [['label', 'text']]]]]]);
    }

    public function test_agent_offer_downloads_as_pdf(): void
    {
        $response = $this->get('/api/v1/legal/agent-offer.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('agentlik', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_application_requires_accepting_the_offer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/agent/profile', $this->kycPayload($user, ['accept_offer' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_offer');

        $this->assertNull($user->profile()->first());
    }

    public function test_application_records_the_accepted_offer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/agent/profile', $this->kycPayload($user))
            ->assertCreated()
            ->assertJsonPath('data.offer.accepted_version', config('legal.agent_offer_version'))
            ->assertJsonPath('data.offer.needs_acceptance', false);

        $profile = $user->profile()->first();
        $this->assertSame(app(PublicOfferService::class)->hash(PublicOfferService::AGENT), $profile->offer_hash);
        $this->assertNotNull($profile->offer_accepted_at);
        $this->assertSame('127.0.0.1', $profile->offer_accepted_ip);
    }

    public function test_admin_cannot_approve_agent_without_accepted_offer(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = AgentProfile::factory()->contractUnderReview()->offerNotAccepted()->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/agents/{$profile->id}/status", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offer');

        $this->assertSame(AgentProfileStatus::Pending, $profile->fresh()->status);
    }

    public function test_outdated_offer_version_blocks_approval_until_reaccepted(): void
    {
        $admin = User::factory()->admin()->create();
        $profile = AgentProfile::factory()->contractUnderReview()->create();
        config(['legal.agent_offer_version' => 'v99']);

        $this->actingAs($profile->user)
            ->getJson('/api/v1/agent/profile')
            ->assertJsonPath('data.offer.needs_acceptance', true);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/agents/{$profile->id}/status", ['status' => 'approved'])
            ->assertUnprocessable();

        $this->actingAs($profile->user)
            ->postJson('/api/v1/agent/profile/accept-offer', ['accept_offer' => true])
            ->assertOk()
            ->assertJsonPath('data.offer.accepted_version', 'v99')
            ->assertJsonPath('data.offer.needs_acceptance', false);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/agents/{$profile->id}/status", ['status' => 'approved'])
            ->assertOk();
    }

    public function test_designers_are_not_bound_by_the_agent_offer(): void
    {
        $profile = AgentProfile::factory()->designer()->offerNotAccepted()->create();

        $this->assertTrue($profile->hasAcceptedCurrentOffer());

        $this->actingAs($profile->user)
            ->postJson('/api/v1/agent/profile/accept-offer', ['accept_offer' => true])
            ->assertNotFound();
    }
}
