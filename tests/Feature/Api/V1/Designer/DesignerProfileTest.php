<?php

namespace Tests\Feature\Api\V1\Designer;

use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignerProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function designer(): array
    {
        $user = User::factory()->create([
            'role' => 'designer',
            'first_name' => 'Dilnoza',
            'last_name' => 'Test',
            'phone' => '+998907654321',
        ]);

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    public function test_designer_profile_is_created_without_kyc_and_approved_instantly(): void
    {
        [, $headers] = $this->designer();
        $category = Category::factory()->designer()->create();

        $this->postJson('/api/v1/designer/profile', [
            'bio' => 'Logotip va brending bo\'yicha 5 yillik tajriba.',
            'category_ids' => [$category->id],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.provider_type', 'designer')
            // No studio name — the public display falls back to the person's name.
            ->assertJsonPath('data.display_name', 'Dilnoza Test');

        // Instantly listable in the public designers feed.
        $this->getJson('/api/v1/agents?type=designer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Dilnoza Test')
            ->assertJsonPath('data.0.provider_type', 'designer');
    }

    public function test_optional_studio_name_wins_over_personal_name(): void
    {
        [, $headers] = $this->designer();
        $category = Category::factory()->designer()->create();

        $this->postJson('/api/v1/designer/profile', [
            'company_name' => 'Pixel Studio',
            'category_ids' => [$category->id],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Pixel Studio');
    }

    public function test_requires_at_least_one_designer_category(): void
    {
        [, $headers] = $this->designer();
        $agentCategory = Category::factory()->create(); // type: agent

        $this->postJson('/api/v1/designer/profile', [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->postJson('/api/v1/designer/profile', [
            'category_ids' => [$agentCategory->id],
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.0');
    }

    public function test_non_designers_cannot_use_the_designer_flow(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $category = Category::factory()->designer()->create();

        $this->postJson('/api/v1/designer/profile', [
            'category_ids' => [$category->id],
        ], ['Authorization' => 'Bearer '.$client->createToken('t')->plainTextToken])
            ->assertForbidden();
    }

    public function test_designer_cannot_apply_through_agency_kyc(): void
    {
        [, $headers] = $this->designer();

        $this->postJson('/api/v1/agent/profile', [], $headers)
            ->assertForbidden();
    }

    public function test_duplicate_profile_is_rejected(): void
    {
        [$user, $headers] = $this->designer();
        AgentProfile::factory()->for($user)->approved()->create();
        $category = Category::factory()->designer()->create();

        $this->postJson('/api/v1/designer/profile', [
            'category_ids' => [$category->id],
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
    }

    public function test_designer_can_manage_phase_two_and_portfolio(): void
    {
        [$user, $headers] = $this->designer();
        $category = Category::factory()->designer()->create();

        $this->postJson('/api/v1/designer/profile', [
            'category_ids' => [$category->id],
        ], $headers)->assertCreated();

        // Phase 2 (bio, workflow, advantages) works exactly like for agencies.
        $this->patchJson('/api/v1/agent/profile', [
            'bio' => 'Yangilangan bio',
            'workflow_steps' => [['title' => 'Brif']],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.bio', 'Yangilangan bio')
            ->assertJsonPath('data.workflow_steps.0.title', 'Brif');
    }
}
