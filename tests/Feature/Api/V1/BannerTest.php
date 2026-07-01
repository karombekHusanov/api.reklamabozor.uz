<?php

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_banners_returns_only_active_ordered(): void
    {
        Banner::factory()->create(['is_active' => true, 'sort_order' => 2, 'title' => 'second']);
        Banner::factory()->create(['is_active' => true, 'sort_order' => 1, 'title' => 'first']);
        Banner::factory()->inactive()->create(['title' => 'hidden']);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'first')
            ->assertJsonPath('data.1.title', 'second');
    }

    public function test_public_banners_endpoint_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/banners')->assertOk();
    }
}
