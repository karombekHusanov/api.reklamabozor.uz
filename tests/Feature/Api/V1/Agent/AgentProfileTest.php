<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Models\Advantage;
use App\Models\AgentPortfolioItem;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string}
     */
    private function authedUser(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    /**
     * Phase 1 — verification application (KYC) payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applicationPayload(User $user, array $overrides = []): array
    {
        $passport = File::factory()->create(['uploaded_by' => $user->id]);
        $certificate = File::factory()->create(['uploaded_by' => $user->id]);

        return array_merge([
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
            'phone' => '+998901112233',
        ], $overrides);
    }

    public function test_agent_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/agent/profile')->assertUnauthorized();
        $this->postJson('/api/v1/agent/profile', [])->assertUnauthorized();
        $this->putJson('/api/v1/agent/profile', [])->assertUnauthorized();
        $this->patchJson('/api/v1/agent/profile', [])->assertUnauthorized();
    }

    public function test_show_returns_null_when_user_has_no_profile(): void
    {
        [, $token] = $this->authedUser();

        $this->getJson('/api/v1/agent/profile', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_user_can_submit_application_as_pending(): void
    {
        [$user, $token] = $this->authedUser();

        $response = $this->postJson('/api/v1/agent/profile', $this->applicationPayload($user), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.company_name', 'Nova Media Group')
            ->assertJsonPath('data.legal_form', 'MChJ')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.completion_percent', 0);

        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => $user->id,
            'company_name' => 'Nova Media Group',
            'mfo' => '00440',
            'status' => 'pending',
        ]);
    }

    public function test_user_cannot_submit_two_applications(): void
    {
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->create();

        $this->postJson('/api/v1/agent/profile', $this->applicationPayload($user), [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_name']);
    }

    public function test_application_validates_required_fields(): void
    {
        [, $token] = $this->authedUser();

        $this->postJson('/api/v1/agent/profile', [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_name', 'legal_form', 'inn', 'director_name', 'director_passport',
                'director_passport_file_id', 'registration_certificate_file_id',
                'bank_name', 'bank_account', 'mfo', 'phone',
            ]);
    }

    public function test_application_rejects_file_not_owned_by_user(): void
    {
        [$user, $token] = $this->authedUser();
        $strangerFile = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/agent/profile', $this->applicationPayload($user, [
            'director_passport_file_id' => $strangerFile->id,
        ]), ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['director_passport_file_id']);
    }

    public function test_rejected_application_can_be_resubmitted_and_returns_to_pending(): void
    {
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->rejected('Missing documents.')->create();

        $response = $this->putJson('/api/v1/agent/profile', $this->applicationPayload($user, [
            'company_name' => 'Resubmitted Co',
        ]), ['Authorization' => 'Bearer '.$token]);

        $response
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Resubmitted Co')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rejection_reason', null);

        $this->assertDatabaseHas('agent_profiles', [
            'user_id' => $user->id,
            'company_name' => 'Resubmitted Co',
            'status' => 'pending',
            'rejection_reason' => null,
        ]);
    }

    public function test_approved_application_cannot_be_resubmitted(): void
    {
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->approved()->create();

        $this->putJson('/api/v1/agent/profile', $this->applicationPayload($user), [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_resubmit_returns_404_without_existing_profile(): void
    {
        [$user, $token] = $this->authedUser();

        $this->putJson('/api/v1/agent/profile', $this->applicationPayload($user), [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();
    }

    public function test_show_returns_existing_profile_with_categories(): void
    {
        [$user, $token] = $this->authedUser();
        $profile = AgentProfile::factory()->for($user)->create();
        $profile->categories()->attach(Category::factory()->count(2)->create());

        $this->getJson('/api/v1/agent/profile', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonCount(2, 'data.categories');
    }

    // --- Phase 2: presentation details (approved only) -----------------------

    public function test_approved_agent_can_update_presentation_details(): void
    {
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->approved()->create();
        $categoryIds = Category::factory()->count(2)->create()->pluck('id')->all();

        $response = $this->patchJson('/api/v1/agent/profile', [
            'bio' => 'Outdoor and digital advertising across Tashkent.',
            'lat' => 41.31,
            'lng' => 69.28,
            'location_label' => 'Yunusabad, Tashkent',
            'category_ids' => $categoryIds,
        ], ['Authorization' => 'Bearer '.$token]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.bio', 'Outdoor and digital advertising across Tashkent.')
            ->assertJsonCount(2, 'data.categories');

        $this->assertDatabaseCount('agent_categories', 2);
    }

    public function test_pending_agent_cannot_update_details(): void
    {
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->create(); // pending

        $this->patchJson('/api/v1/agent/profile', ['bio' => 'Too soon.'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_update_details_returns_404_without_profile(): void
    {
        [, $token] = $this->authedUser();

        $this->patchJson('/api/v1/agent/profile', ['bio' => 'x'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();
    }

    public function test_details_accept_both_agent_and_designer_categories(): void
    {
        // Unified provider model: a provider may offer advertising and/or design.
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->approved()->create();
        $agentCategory = Category::factory()->create();
        $designerCategory = Category::factory()->designer()->create();

        $this->patchJson('/api/v1/agent/profile', [
            'category_ids' => [$agentCategory->id, $designerCategory->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(2, 'data.categories');
    }

    public function test_details_reject_inactive_categories(): void
    {
        [$user, $token] = $this->authedUser();
        AgentProfile::factory()->for($user)->approved()->create();
        $inactive = Category::factory()->inactive()->create();

        $this->patchJson('/api/v1/agent/profile', [
            'category_ids' => [$inactive->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_ids.0']);
    }

    public function test_completion_percent_reflects_filled_presentation_fields(): void
    {
        [$user, $token] = $this->authedUser();
        $logo = File::factory()->create(['uploaded_by' => $user->id]);
        AgentProfile::factory()->for($user)->approved()->create([
            'results_text' => null,
            'website_url' => null,
            'linkedin_url' => null,
        ]);
        $categoryIds = Category::factory()->count(1)->create()->pluck('id')->all();

        // logo (15) + location (15) + categories (15) + bio (10) = 55
        $this->patchJson('/api/v1/agent/profile', [
            'company_logo_file_id' => $logo->id,
            'lat' => 41.31,
            'lng' => 69.28,
            'location_label' => 'Tashkent',
            'bio' => 'About us.',
            'category_ids' => $categoryIds,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.completion_percent', 55);
    }

    public function test_completion_percent_counts_portfolio_and_advantages(): void
    {
        [$user, $token] = $this->authedUser();
        $profile = AgentProfile::factory()->for($user)->approved()->create([
            'results_text' => null,
            'website_url' => null,
            'linkedin_url' => null,
            'bio' => null,
            'lat' => null,
            'lng' => null,
            'location_label' => null,
        ]);
        $advantage = Advantage::factory()->create();
        AgentPortfolioItem::factory()->for($profile)->create();

        // portfolio (20) + advantages (10) = 30 on an otherwise empty profile
        $this->patchJson('/api/v1/agent/profile', [
            'advantage_ids' => [$advantage->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.completion_percent', 30);
    }
}
