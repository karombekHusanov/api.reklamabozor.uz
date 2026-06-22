<?php

namespace Tests\Feature\Api\V1;

use App\Models\AgentProfile;
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
}
