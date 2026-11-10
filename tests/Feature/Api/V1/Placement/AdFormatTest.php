<?php

namespace Tests\Feature\Api\V1\Placement;

use App\Models\AdFormat;
use App\Models\AdPlacement;
use Database\Seeders\AdFormatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Metro format catalogue (GET /ad-formats) and its rows in the calculator. */
class AdFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdFormatSeeder::class);
    }

    private function id(string $code): int
    {
        return AdFormat::query()->where('code', $code)->value('id');
    }

    public function test_catalogue_lists_the_operator_formats_with_prices_and_line_counts(): void
    {
        $this->seed(AdFormatSeeder::class); // idempotent

        $data = collect($this->getJson('/api/v1/ad-formats')->assertOk()->json('data'));

        $this->assertCount(15, $data);
        $this->assertSame(3, $data->where('is_package', true)->count());

        $panel = $data->firstWhere('code', 'metro_track_panel');
        $this->assertSame('static', $panel['medium']);
        $this->assertSame(115, $panel['total_count']);
        $this->assertSame(59, $panel['line_counts']['chilonzor']);
        $this->assertSame(3, $panel['min_months']);
        $this->assertFalse($panel['production_included']);
        $this->assertSame(2_200_000, $panel['min_month_price_som']);
        $this->assertSame([], $panel['slot_options']);

        $bench = $data->firstWhere('code', 'metro_led_bench');
        $this->assertSame([5, 10], $bench['slot_options']);
        $this->assertSame(3_500_000, $bench['min_month_price_som']);
        $this->assertSame('Trans Group', $bench['owner']['name']);
    }

    public function test_digital_format_follows_clip_days_and_quantity(): void
    {
        $data = $this->postJson('/api/v1/placements/estimate', [
            'formats' => [['format_id' => $this->id('metro_led_bench'), 'quantity' => 2]],
            'days' => 45,
            'slot_seconds' => 10,
        ])->assertOk()->json('data');

        // 4 800 000 × 1.5 months × 2 units.
        $this->assertSame(14_400_000, $data['format_items'][0]['price_som']);
        $this->assertFalse($data['format_items'][0]['term_adjusted']);
        $this->assertSame(14_400_000, $data['subtotal_som']);
        $this->assertSame([], $data['items']);
    }

    public function test_static_format_bills_whole_months_from_its_minimum_term_and_ignores_the_clip(): void
    {
        $row = fn (int $days) => $this->postJson('/api/v1/placements/estimate', [
            'formats' => [['format_id' => $this->id('metro_lightbox_hanging'), 'quantity' => 4]],
            'days' => $days,
            'slot_seconds' => 20,
        ])->assertOk()->json('data.format_items.0');

        // 650 000 × 3 months (minimum) × 4.
        $this->assertSame(7_800_000, $row(30)['price_som']);
        $this->assertTrue($row(30)['term_adjusted']);
        $this->assertSame(90, $row(30)['billed_days']);
        // 100 days → 4 whole months.
        $this->assertSame(10_400_000, $row(100)['price_som']);
    }

    public function test_packages_are_never_multiplied_and_missing_clip_lengths_stay_unpriced(): void
    {
        $data = $this->postJson('/api/v1/placements/estimate', [
            'formats' => [
                ['format_id' => $this->id('metro_wagon_monitors'), 'quantity' => 5],
                ['format_id' => $this->id('metro_led_exit')],
            ],
            'days' => 30,
            'slot_seconds' => 5,
        ])->assertOk()->json('data');

        $this->assertSame(1, $data['format_items'][0]['quantity']);
        $this->assertSame(20_000_000, $data['format_items'][0]['price_som']);
        $this->assertSame(23_500_000, $data['subtotal_som']);

        $unpriced = $this->postJson('/api/v1/placements/estimate', [
            'formats' => [['format_id' => $this->id('metro_led_exit')]], 'days' => 30, 'slot_seconds' => 15,
        ])->assertOk()->json('data');
        $this->assertFalse($unpriced['format_items'][0]['priced'], 'the metro sells 5 s and 10 s clips only');
        $this->assertSame(1, $unpriced['unpriced_count']);
    }

    public function test_screens_and_metro_formats_add_up_in_one_estimate(): void
    {
        $screen = AdPlacement::factory()->create();
        $screen->rates()->create(['unit' => 'month', 'slot_seconds' => 5, 'shows_per_hour' => 0, 'price_tiyin' => 520_000_000]);

        $data = $this->postJson('/api/v1/placements/estimate', [
            'placement_ids' => [$screen->id],
            'formats' => [['format_id' => $this->id('metro_led_stand_360'), 'quantity' => 1]],
            'days' => 30,
            'slot_seconds' => 5,
        ])->assertOk()->json('data');

        $this->assertSame(8_700_000, $data['subtotal_som']);
        $this->assertSame(1_044_000, $data['vat_som']);
        $this->assertSame(2, $data['priced_count']);
    }

    public function test_metro_lines_cover_every_line_the_formats_are_counted_on(): void
    {
        $data = $this->getJson('/api/v1/metro/lines')->assertOk()->json('data');
        $lines = collect($data['lines'])->keyBy('code');

        $this->assertEqualsCanonicalizing(['chilonzor', 'ozbekiston', 'yunusobod', 'halqa'], $lines->keys()->all());
        foreach ($lines as $line) {
            $this->assertGreaterThanOrEqual(5, count($line['stations']));
            $this->assertEqualsWithDelta(41.3, $line['stations'][0]['lat'], 0.2);
            $this->assertEqualsWithDelta(69.3, $line['stations'][0]['lng'], 0.3);
            $this->assertNotSame('', $line['stations'][0]['name_uz']);
        }

        $counted = AdFormat::query()->get()->flatMap(fn (AdFormat $f) => array_keys($f->line_counts))->unique();
        $this->assertSame([], $counted->diff($lines->keys())->values()->all());
    }

    public function test_an_estimate_needs_screens_or_formats(): void
    {
        $this->postJson('/api/v1/placements/estimate', ['days' => 30, 'slot_seconds' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['placement_ids', 'formats']);
    }
}
