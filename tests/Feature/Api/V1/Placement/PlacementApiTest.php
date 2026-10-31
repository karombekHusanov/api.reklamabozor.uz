<?php

namespace Tests\Feature\Api\V1\Placement;

use App\Enums\AdPlacementStatus;
use App\Models\AdPlacement;
use App\Models\AdPlacementRate;
use App\Models\AgentProfile;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Public map layer: GET /placements and GET /placements/{id}. */
class PlacementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_only_bookable_placements_for_guests(): void
    {
        $led = AdPlacement::defaultCategory(true);
        $this->assertSame('Tashqi reklama', $led->parent->name_uz);
        $this->assertSame(1, $led->depth);

        $shown = AdPlacement::factory()->create(['lat' => 41.30, 'lng' => 69.28]);
        $active = AdPlacement::factory()->status(AdPlacementStatus::Active)->create();
        AdPlacement::factory()->status(AdPlacementStatus::Hidden)->create();
        AdPlacement::factory()->status(AdPlacementStatus::NotForSale)->create();
        AdPlacement::factory()->create()->delete();

        $response = $this->getJson('/api/v1/placements')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$shown->id, $active->id], $ids);

        $row = collect($response->json('data'))->firstWhere('id', $shown->id);
        $this->assertSame('led_screen', $row['kind']);
        $this->assertFalse($row['has_price']);
        $this->assertNull($row['min_day_price_som']);
        $this->assertFalse($row['owner']['on_platform']);
        $this->assertSame($shown->owner_name_raw, $row['owner']['name']);
        $this->assertSame($led->id, $row['category_id'], 'the LED category is exposed so the form can preselect it');
    }

    public function test_list_filters_by_bbox_region_and_price(): void
    {
        $region = Region::factory()->create();
        $inside = AdPlacement::factory()->create(['lat' => 41.30, 'lng' => 69.28, 'region_id' => $region->id]);
        $outside = AdPlacement::factory()->create(['lat' => 41.35, 'lng' => 69.36]);
        AdPlacementRate::query()->create([
            'ad_placement_id' => $inside->id, 'unit' => 'day', 'slot_seconds' => 15, 'shows_per_hour' => 6, 'price_tiyin' => 25_000_000,
        ]);

        $this->getJson('/api/v1/placements?bbox=41.29,69.27,41.31,69.29')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inside->id);

        $this->getJson('/api/v1/placements?region_id='.$region->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inside->id);

        $this->getJson('/api/v1/placements?with_price=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.has_price', true)
            ->assertJsonPath('data.0.min_day_price_som', 250000);

        $this->assertCount(2, $this->getJson('/api/v1/placements')->json('data'));
        $this->assertSame($outside->id, $this->getJson('/api/v1/placements?q='.urlencode($outside->owner_name_raw))->json('data.0.id'));
    }

    public function test_detail_exposes_rates_and_platform_owner(): void
    {
        $owner = AgentProfile::factory()->for(User::factory())->approved()->create(['company_name' => 'Media Lux']);
        $placement = AdPlacement::factory()->ownedBy($owner)->create();
        AdPlacementRate::query()->create([
            'ad_placement_id' => $placement->id, 'unit' => 'day', 'slot_seconds' => 15, 'shows_per_hour' => 6, 'price_tiyin' => 30_000_000,
        ]);

        $this->getJson('/api/v1/placements/'.$placement->id)
            ->assertOk()
            ->assertJsonPath('data.owner.on_platform', true)
            ->assertJsonPath('data.owner.agent_profile_id', $owner->id)
            ->assertJsonPath('data.owner.name', 'Media Lux')
            ->assertJsonPath('data.rates.0.price_som', 300000)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_hidden_placement_detail_is_404(): void
    {
        $hidden = AdPlacement::factory()->status(AdPlacementStatus::Hidden)->create();

        $this->getJson('/api/v1/placements/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/placements/999999')->assertNotFound();
    }
}
