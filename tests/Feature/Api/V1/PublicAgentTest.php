<?php

namespace Tests\Feature\Api\V1;

use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_agents_listing_is_open_and_only_approved(): void
    {
        AgentProfile::factory()->approved()->count(2)->create();
        AgentProfile::factory()->create(); // pending
        AgentProfile::factory()->rejected()->create();

        $this->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_public_agents_are_ranked_by_completion(): void
    {
        // Low completion: no presentation fields.
        $low = AgentProfile::factory()->approved()->create([
            'company_logo_file_id' => null,
            'bio' => null,
            'results_text' => null,
            'website_url' => null,
            'linkedin_url' => null,
            'lat' => null,
            'lng' => null,
            'location_label' => null,
        ]);

        // High completion: bio + results + website + location filled.
        $high = AgentProfile::factory()->approved()->create([
            'bio' => 'About us',
            'results_text' => 'Great results',
            'website_url' => 'https://x.uz',
            'lat' => 41.31,
            'lng' => 69.28,
            'location_label' => 'Tashkent',
        ]);

        $response = $this->getJson('/api/v1/agents?limit=2')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$high->id, $low->id], $ids);
    }

    public function test_limit_is_respected(): void
    {
        AgentProfile::factory()->approved()->count(5)->create();

        $this->getJson('/api/v1/agents?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_nearby_orders_agents_by_distance_and_includes_distance(): void
    {
        // Reference point (Tashkent center).
        $lat = 41.3111;
        $lng = 69.2797;

        $near = AgentProfile::factory()->approved()->create(['lat' => 41.3120, 'lng' => 69.2800, 'company_name' => 'Near']);
        $far = AgentProfile::factory()->approved()->create(['lat' => 41.5500, 'lng' => 69.6000, 'company_name' => 'Far']);
        AgentProfile::factory()->approved()->create(['lat' => null, 'lng' => null]); // excluded (no coords)
        AgentProfile::factory()->create(['lat' => 41.3111, 'lng' => 69.2797]); // pending, excluded

        $response = $this->getJson("/api/v1/agents/nearby?lat={$lat}&lng={$lng}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$near->id, $far->id], $ids);
        $this->assertIsInt($response->json('data.0.distance_m'));
        $this->assertLessThan($response->json('data.1.distance_m'), $response->json('data.0.distance_m'));
    }

    public function test_nearby_respects_limit(): void
    {
        AgentProfile::factory()->approved()->count(4)->create(['lat' => 41.31, 'lng' => 69.28]);

        $this->getJson('/api/v1/agents/nearby?lat=41.31&lng=69.28&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_nearby_requires_coordinates(): void
    {
        $this->getJson('/api/v1/agents/nearby')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_public_agents_can_be_filtered_by_designer_category_type(): void
    {
        $designerCategory = Category::factory()->designer()->create();
        $agentCategory = Category::factory()->create();

        $designerProfile = AgentProfile::factory()->approved()->create(['company_name' => 'Studio A']);
        $designerProfile->categories()->attach($designerCategory);

        $agentOnlyProfile = AgentProfile::factory()->approved()->create(['company_name' => 'Agency B']);
        $agentOnlyProfile->categories()->attach($agentCategory);

        $this->getJson('/api/v1/agents?type=designer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $designerProfile->id)
            ->assertJsonPath('data.0.company_name', 'Studio A');
    }

    public function test_provider_type_is_pinned_to_the_profile_not_the_owners_active_role(): void
    {
        // An approved agency whose owner later flips their active role to
        // designer (multirole). The agency profile must still read as 'agent' —
        // provider_type comes from the profile, not the live role.
        $owner = User::factory()->create([
            'role' => 'designer',
            'roles' => ['client', 'agent', 'designer'],
        ]);
        $profile = AgentProfile::factory()->for($owner)->approved()->create();

        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider_type', 'agent');
    }

    public function test_agency_stays_visible_as_agent_after_owner_switches_active_role_to_client(): void
    {
        // Aziz is an agent; a client is viewing his profile. Aziz switches his
        // own active role to client. The client must still see him as an agent:
        // the public profile is keyed on the (approved) profile row, not Aziz's
        // live active role.
        $aziz = User::factory()->create(['role' => 'client', 'roles' => ['client', 'agent']]);
        $profile = AgentProfile::factory()->for($aziz)->approved()->create();

        // Listed in the public marketplace…
        $this->getJson('/api/v1/agents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $profile->id)
            ->assertJsonPath('data.0.provider_type', 'agent');

        // …and reachable by detail, still typed as an agent.
        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.provider_type', 'agent');
    }

    public function test_public_agent_detail_exposes_presentation_fields_and_approved_reviews(): void
    {
        $agentUser = User::factory()->create(['first_name' => 'Bekzod', 'last_name' => 'Aliyev']);
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create([
            'bio' => 'Outdoor ads agency',
            'results_text' => '500+ billboards installed',
            'website_url' => 'https://agency.uz',
            'linkedin_url' => 'https://linkedin.com/company/agency',
            'location_label' => 'Tashkent',
        ]);

        Review::factory()->approved()->create([
            'agent_id' => $agentUser->id,
            'rating' => 5,
            'comment' => 'Great work!',
        ]);
        Review::factory()->create([
            'agent_id' => $agentUser->id,
            'rating' => 1,
            'comment' => 'Pending review',
        ]);

        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.bio', 'Outdoor ads agency')
            ->assertJsonPath('data.results_text', '500+ billboards installed')
            ->assertJsonPath('data.website_url', 'https://agency.uz')
            ->assertJsonPath('data.linkedin_url', 'https://linkedin.com/company/agency')
            ->assertJsonPath('data.location_label', 'Tashkent')
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.rating', 5)
            ->assertJsonPath('data.reviews.0.comment', 'Great work!')
            ->assertJsonPath('data.user_id', $agentUser->id);
    }
}
