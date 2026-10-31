<?php

namespace Tests\Feature\Api\V1\Placement;

use App\Enums\AdPlacementStatus;
use App\Models\AdPlacement;
use App\Models\Region;
use Database\Seeders\RegionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlacementImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'placements').'.csv';
        file_put_contents($path, "owner_name,owner_kind,owner_ref,district_code,address,lat,lng\n".$body);

        return $path;
    }

    public function test_import_creates_rows_marks_brands_and_groups_neighbours(): void
    {
        // The base TestCase seeds regions; reuse Tashkent and its district.
        $tashkent = Region::query()->firstOrCreate(
            ['code' => 'toshkent-shahri'],
            ['name_uz' => 'Toshkent shahri', 'name_ru' => 'город Ташкент', 'is_active' => true, 'sort_order' => 0],
        );
        $district = Region::query()->firstOrCreate(
            ['code' => 'yunusobod'],
            ['parent_id' => $tashkent->id, 'name_uz' => 'Yunusobod', 'name_ru' => 'Юнусабад', 'is_active' => true, 'sort_order' => 0],
        );

        $path = $this->csv(implode("\n", [
            'MEDIA LUX,legal,1,yunusobod,"Amir Temur, 66",41.327870,69.283626',
            'MEDIA LUX,legal,2,yunusobod,"Amir Temur, 66 (ikkinchi tomon)",41.327900,69.283700',
            'MOBIUZ,brand,1,yunusobod,"Oybek, 1",41.295000,69.283000',
            'APEX,legal,7,nowhere,"Chilonzor, 3",41.288000,69.229000',
        ]));

        $this->artisan('placements:import', ['path' => $path])
            ->expectsOutputToContain('4 created, 0 updated, 0 skipped.')
            ->assertSuccessful();

        $this->assertSame(4, AdPlacement::count());

        $first = AdPlacement::query()->where('import_key', 'MEDIA LUX|1')->firstOrFail();
        $second = AdPlacement::query()->where('import_key', 'MEDIA LUX|2')->firstOrFail();
        $this->assertSame($district->id, $first->region_id);
        $this->assertSame(AdPlacementStatus::Unclaimed, $first->status);
        $this->assertNotNull($first->group_key, 'two screens 10 m apart share a group');
        $this->assertSame($first->group_key, $second->group_key);

        $brand = AdPlacement::query()->where('owner_name_raw', 'MOBIUZ')->firstOrFail();
        $this->assertSame(AdPlacementStatus::NotForSale, $brand->status);
        $this->assertNull($brand->group_key);

        $apex = AdPlacement::query()->where('owner_name_raw', 'APEX')->firstOrFail();
        $this->assertNull($apex->region_id, 'unknown district → imported without a region');
    }

    public function test_reimport_is_idempotent_and_keeps_platform_fields(): void
    {
        $path = $this->csv('MEDIA LUX,legal,1,yunusobod,"Amir Temur, 66",41.327870,69.283626');
        $this->artisan('placements:import', ['path' => $path])->assertSuccessful();

        $row = AdPlacement::query()->firstOrFail();
        $row->forceFill(['status' => AdPlacementStatus::Hidden])->save();

        $path = $this->csv('MEDIA LUX,legal,1,yunusobod,"Amir Temur, 66 (yangilandi)",41.327871,69.283626');
        $this->artisan('placements:import', ['path' => $path])
            ->expectsOutputToContain('0 created, 1 updated, 0 skipped.')
            ->assertSuccessful();

        $this->assertSame(1, AdPlacement::count());
        $row->refresh();
        $this->assertSame('Amir Temur, 66 (yangilandi)', $row->address);
        $this->assertSame(AdPlacementStatus::Hidden, $row->status, 'status set on the platform survives a re-import');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $path = $this->csv('MEDIA LUX,legal,1,yunusobod,"Amir Temur, 66",41.327870,69.283626');
        $this->artisan('placements:import', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run] 1 created')
            ->assertSuccessful();

        $this->assertSame(0, AdPlacement::count());
    }

    public function test_bundled_tashkent_csv_imports_cleanly(): void
    {
        $this->seed(RegionSeeder::class);

        $this->artisan('placements:import', ['path' => database_path('data/ad_placements/tashkent_led_2026-10.csv')])
            ->expectsOutputToContain('301 created, 0 updated, 0 skipped.')
            ->assertSuccessful();

        $this->assertSame(15, AdPlacement::query()->where('status', AdPlacementStatus::NotForSale)->count());
        $this->assertSame(0, AdPlacement::query()->whereNull('region_id')->count(), 'every district maps to a seeded region');
    }
}
