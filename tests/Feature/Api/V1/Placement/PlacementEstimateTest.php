<?php

namespace Tests\Feature\Api\V1\Placement;

use App\Enums\AdPlacementStatus;
use App\Models\AdPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Map calculator (POST /placements/estimate) and the price-list import behind it. */
class PlacementEstimateTest extends TestCase
{
    use RefreshDatabase;

    private function screen(array $monthlyBySeconds, array $attributes = []): AdPlacement
    {
        $placement = AdPlacement::factory()->create($attributes);
        foreach ($monthlyBySeconds as $seconds => $som) {
            $placement->rates()->create([
                'unit' => 'month', 'slot_seconds' => $seconds, 'shows_per_hour' => 0, 'price_tiyin' => $som * 100,
            ]);
        }

        return $placement;
    }

    public function test_estimate_prorates_the_monthly_rate_of_the_chosen_clip_and_adds_vat(): void
    {
        $big = $this->screen([5 => 14_000_000, 10 => 16_000_000], ['specs' => ['name' => 'Magic city', 'width_m' => 16, 'height_m' => 6]]);
        $small = $this->screen([5 => 3_600_000, 10 => 4_375_000]);
        config(['placements.min_estimate_days' => 1]);

        $data = $this->postJson('/api/v1/placements/estimate', [
            'placement_ids' => [$big->id, $small->id],
            'days' => 15,
            'slot_seconds' => 10,
        ])->assertOk()->json('data');

        // 16 000 000 / 30 × 15 and 4 375 000 / 30 × 15, each rounded to a so'm.
        $this->assertSame(8_000_000, $data['items'][0]['price_som']);
        $this->assertSame(2_187_500, $data['items'][1]['price_som']);
        $this->assertSame('Magic city', $data['items'][0]['name']);
        $this->assertSame('16×6', $data['items'][0]['size']);
        $this->assertSame([5, 10], $data['items'][0]['slot_options']);
        $this->assertSame(16_000_000, $data['items'][0]['rate']['price_som']);
        $this->assertSame(10_187_500, $data['subtotal_som']);
        $this->assertSame(12, $data['vat_percent']);
        $this->assertSame(1_222_500, $data['vat_som']);
        $this->assertSame(11_410_000, $data['total_som']);
        $this->assertSame(2, $data['priced_count']);
    }

    public function test_a_full_month_costs_exactly_the_listed_price(): void
    {
        $screen = $this->screen([20 => 7_200_000]);

        $data = $this->postJson('/api/v1/placements/estimate', [
            'placement_ids' => [$screen->id], 'days' => 30, 'slot_seconds' => 20,
        ])->assertOk()->json('data');

        $this->assertSame(7_200_000, $data['subtotal_som']);
        $this->assertSame(240_000, $data['items'][0]['day_price_som']);
    }

    public function test_screens_without_a_rate_for_the_clip_stay_unpriced_and_hidden_ones_are_dropped(): void
    {
        $priced = $this->screen([5 => 3_000_000]);
        $noRate = AdPlacement::factory()->create();
        $hidden = $this->screen([5 => 3_000_000], ['status' => AdPlacementStatus::Hidden]);

        $data = $this->postJson('/api/v1/placements/estimate', [
            'placement_ids' => [$priced->id, $noRate->id, $hidden->id], 'days' => 30, 'slot_seconds' => 15,
        ])->assertOk()->json('data');

        $this->assertCount(2, $data['items']);
        $this->assertFalse($data['items'][0]['priced'], 'a 15 s clip is not on a 5 s-only card — never interpolated');
        $this->assertNull($data['items'][0]['price_som']);
        $this->assertSame(0, $data['total_som']);
        $this->assertSame(2, $data['unpriced_count']);
    }

    public function test_bookings_shorter_than_the_minimum_term_are_not_quoted(): void
    {
        $screen = $this->screen([5 => 5_200_000]);
        $payload = ['placement_ids' => [$screen->id], 'slot_seconds' => 5];

        $this->postJson('/api/v1/placements/estimate', [...$payload, 'days' => 29])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['days']);

        $data = $this->postJson('/api/v1/placements/estimate', [...$payload, 'days' => 45])->assertOk()->json('data');
        $this->assertSame(30, $data['min_days']);
        $this->assertSame(7_800_000, $data['subtotal_som']);
    }

    public function test_estimate_validates_input(): void
    {
        $this->postJson('/api/v1/placements/estimate', ['placement_ids' => [], 'days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['placement_ids', 'days', 'slot_seconds']);
    }

    public function test_list_exposes_day_price_from_a_monthly_card_and_the_clip_choices(): void
    {
        $screen = $this->screen([5 => 3_600_000, 20 => 7_200_000], ['specs' => ['name' => 'Chorsu']]);

        $row = collect($this->getJson('/api/v1/placements')->assertOk()->json('data'))->firstWhere('id', $screen->id);

        $this->assertTrue($row['has_price']);
        $this->assertSame(120_000, $row['min_day_price_som']);
        $this->assertSame(3_600_000, $row['min_month_price_som'], 'the headline price is the monthly one');
        $this->assertSame([5, 20], $row['slot_options']);
        $this->assertSame('Chorsu', $row['name']);
    }

    public function test_price_list_import_attaches_to_nearby_screens_and_creates_the_rest(): void
    {
        $existing = AdPlacement::factory()->create([
            'owner_name_raw' => 'MEDIA LUX', 'lat' => 41.305000, 'lng' => 69.246100, 'owner_phones' => ['+998 71 230-48-48'],
        ]);
        $other = AdPlacement::factory()->create(['owner_name_raw' => 'APEX', 'lat' => 41.305000, 'lng' => 69.246100]);

        $path = tempnam(sys_get_temp_dir(), 'rates').'.csv';
        file_put_contents($path, implode("\n", [
            'ref,name,tier,address_ru,lat,lng,width_m,height_m,resolution,sides,shows_per_day,ots,traffic_class,price_5s,price_10s,price_15s,price_20s,coords_confirmed',
            '3346,Magic city,big,"Ул. Фурката",41.305009,69.246166,16,6,1920x720,1,300,180000,premium,14000000,16000000,18000000,19000000,1',
            '708,Бочка,small,"Чарвак",41.622910,69.941790,6,3,720x360,1,300,126000,highway,3600000,4375000,5900000,7200000,1',
            '12129,Уч Кахрамон,small,"ТКАД",,,4,8,960x480,2,300,,,3600000,4375000,5900000,7200000,',
        ]));

        $this->artisan('placements:import-rates', ['path' => $path])
            ->expectsOutputToContain('1 matched, 1 created, 1 skipped, 8 rates.')
            ->assertSuccessful();
        // Re-running replaces the rates instead of stacking them.
        $this->artisan('placements:import-rates', ['path' => $path])
            ->expectsOutputToContain('2 matched, 0 created, 1 skipped, 8 rates.')
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('Magic city', $existing->displayName());
        $this->assertSame(180000, $existing->specs['ots']);
        $this->assertSame(4, $existing->rates()->count());
        $this->assertSame(1_400_000_000, $existing->rates()->where('slot_seconds', 5)->value('price_tiyin'));
        $this->assertSame(0, $other->rates()->count(), 'another owner\'s screen on the same spot is left alone');

        $created = AdPlacement::query()->where('import_key', 'MEDIA LUX|price:708')->firstOrFail();
        $this->assertSame(['+998 71 230-48-48'], $created->owner_phones);
        $this->assertNull($created->region_id, 'far outside the city — no district guessed');
        $this->assertSame(3, AdPlacement::count());
    }
}
