<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\CategoryType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Services\Agent\AgentProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 4: capability is derived from category types, and agency (agent-type)
 * categories require a legal-entity profile. PROFILE_ARCHITECTURE.md §3, R3.
 */
class CapabilityGateTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AgentProfileService
    {
        return app(AgentProfileService::class);
    }

    public function test_served_capabilities_are_derived_from_category_types(): void
    {
        $profile = AgentProfile::factory()->approved()->create(); // legal (agent)
        $profile->categories()->attach(Category::factory()->create(['type' => CategoryType::Agent]));
        $profile->categories()->attach(Category::factory()->create(['type' => CategoryType::Designer]));

        $caps = $profile->fresh()->servedCapabilities();
        sort($caps);
        $this->assertSame(['agent', 'designer'], $caps);
    }

    public function test_a_legal_entity_may_serve_both_agent_and_designer_categories(): void
    {
        $profile = AgentProfile::factory()->approved()->create(); // agent = legal
        $agentCat = Category::factory()->create(['type' => CategoryType::Agent]);
        $designCat = Category::factory()->create(['type' => CategoryType::Designer]);

        $this->service()->updateDetails($profile, [
            'category_ids' => [$agentCat->id, $designCat->id],
        ]);

        $this->assertEqualsCanonicalizing(
            ['agent', 'designer'],
            $profile->fresh()->servedCapabilities(),
        );
    }

    public function test_an_individual_designer_cannot_list_agency_categories(): void
    {
        $profile = AgentProfile::factory()->designer()->approved()->create(); // individual
        $agentCat = Category::factory()->create(['type' => CategoryType::Agent]);

        $this->expectException(ValidationException::class);
        $this->service()->updateDetails($profile, ['category_ids' => [$agentCat->id]]);
    }

    public function test_an_individual_designer_may_still_list_designer_categories(): void
    {
        $profile = AgentProfile::factory()->designer()->approved()->create();
        $designCat = Category::factory()->create(['type' => CategoryType::Designer]);

        $this->service()->updateDetails($profile, ['category_ids' => [$designCat->id]]);

        $this->assertSame(['designer'], $profile->fresh()->servedCapabilities());
    }
}
