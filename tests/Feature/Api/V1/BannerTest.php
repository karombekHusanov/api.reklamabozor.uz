<?php

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use App\Models\User;
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

    public function test_public_banners_expose_stats(): void
    {
        Banner::factory()->create(['impressions_count' => 200, 'clicks_count' => 10]);

        $this->getJson('/api/v1/banners')
            ->assertOk()
            ->assertJsonPath('data.0.impressions', 200)
            ->assertJsonPath('data.0.clicks', 10)
            ->assertJsonPath('data.0.ctr', 5);
    }

    public function test_guest_can_record_impression(): void
    {
        $banner = Banner::factory()->create(['impressions_count' => 0]);

        $this->postJson("/api/v1/banners/{$banner->id}/view")->assertOk();

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'impressions_count' => 1]);
    }

    public function test_guest_can_record_click_without_attribution(): void
    {
        $banner = Banner::factory()->create(['clicks_count' => 0]);

        $this->postJson("/api/v1/banners/{$banner->id}/click")->assertOk();

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'clicks_count' => 1]);
        $this->assertDatabaseHas('banner_clicks', ['banner_id' => $banner->id, 'user_id' => null]);
    }

    public function test_click_is_attributed_to_authenticated_user(): void
    {
        $banner = Banner::factory()->create(['clicks_count' => 0]);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/banners/{$banner->id}/click", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertDatabaseHas('banner_clicks', ['banner_id' => $banner->id, 'user_id' => $user->id]);
    }

    public function test_tracking_unknown_banner_returns_not_found(): void
    {
        $this->postJson('/api/v1/banners/999999/view')->assertNotFound();
    }
}
