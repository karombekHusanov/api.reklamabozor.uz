<?php

namespace App\Console\Commands;

use App\Enums\AdPlacementKind;
use App\Enums\AdPlacementStatus;
use App\Models\AdPlacement;
use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load advertising placements from a CSV into `ad_placements`.
 *
 * CSV columns (header required): owner_name, owner_kind (legal|individual|brand),
 * optional owner_phone ("; "-separated), owner_website, owner_email;
 * owner_ref, district_code (regions.code), address, lat, lng. The bundled
 * `database/data/ad_placements/tashkent_led_2026-10.csv` was exported from the
 * owners' Excel list (301 LED screens, Tashkent).
 *
 * Idempotent on `import_key` = "owner_name|owner_ref": re-running updates the
 * address/coordinates of known rows and never touches owner/status/rates set
 * on the platform. Brand-owned screens (owner_kind=brand) are imported as
 * `not_for_sale` so they never show on the map.
 */
class ImportAdPlacements extends Command
{
    protected $signature = 'placements:import
        {path : CSV file to import}
        {--kind=led_screen : Placement kind for every row}
        {--dry-run : Parse and report without writing}';

    protected $description = 'Import advertising placements (LED screens) from a CSV';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $kind = AdPlacementKind::tryFrom((string) $this->option('kind'));
        if ($kind === null) {
            $this->error('Unknown kind: '.$this->option('kind'));

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $regions = Region::query()->pluck('id', 'code');
        $created = $updated = $skipped = 0;
        $unknownDistricts = [];

        DB::transaction(function () use ($rows, $kind, $regions, $dryRun, &$created, &$updated, &$skipped, &$unknownDistricts): void {
            foreach ($rows as $line => $row) {
                $lat = (float) $row['lat'];
                $lng = (float) $row['lng'];
                if ($row['owner_name'] === '' || $row['address'] === '' || abs($lat) > 90 || abs($lng) > 180 || ($lat === 0.0 && $lng === 0.0)) {
                    $this->warn("Line {$line}: incomplete row skipped");
                    $skipped++;

                    continue;
                }

                $regionId = $regions[$row['district_code']] ?? null;
                if ($regionId === null && $row['district_code'] !== '') {
                    $unknownDistricts[$row['district_code']] = true;
                }

                $importKey = mb_substr($row['owner_name'].'|'.$row['owner_ref'], 0, 260);
                $existing = AdPlacement::withTrashed()->where('import_key', $importKey)->first();

                $attributes = [
                    'owner_name_raw' => mb_substr($row['owner_name'], 0, 200),
                    'owner_kind' => $row['owner_kind'] !== '' ? $row['owner_kind'] : null,
                    'external_ref' => $row['owner_ref'] !== '' ? mb_substr($row['owner_ref'], 0, 60) : null,
                    'owner_phones' => $this->splitPhones($row['owner_phone'] ?? ''),
                    'owner_website' => ($row['owner_website'] ?? '') !== '' ? mb_substr($row['owner_website'], 0, 200) : null,
                    'owner_email' => ($row['owner_email'] ?? '') !== '' ? mb_substr($row['owner_email'], 0, 200) : null,
                    'region_id' => $regionId,
                    'address' => mb_substr($row['address'], 0, 300),
                    'lat' => $lat,
                    'lng' => $lng,
                ];

                if ($existing !== null) {
                    $updated++;
                    if (! $dryRun) {
                        // Platform-side fields (status, owner, rates) are never overwritten.
                        $existing->fill($attributes)->save();
                    }

                    continue;
                }

                $created++;
                if (! $dryRun) {
                    AdPlacement::query()->create([
                        ...$attributes,
                        'kind' => $kind,
                        'status' => $row['owner_kind'] === 'brand' ? AdPlacementStatus::NotForSale : AdPlacementStatus::Unclaimed,
                        'import_key' => $importKey,
                        'source' => 'import',
                        'imported_at' => now(),
                    ]);
                }
            }

            if (! $dryRun) {
                $this->assignGroupKeys();
                // Orders built from these screens need their home category.
                AdPlacement::defaultCategory(true);
            }

            if ($dryRun) {
                DB::rollBack();
                DB::beginTransaction();
            }
        });

        foreach (array_keys($unknownDistricts) as $code) {
            $this->warn("Unknown district code '{$code}' — rows imported without a region");
        }

        $this->info(sprintf(
            '%s%d created, %d updated, %d skipped.',
            $dryRun ? '[dry-run] ' : '',
            $created,
            $updated,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * "+998 71 230-48-48; +998 90 …" → list; empty / placeholder cells → null.
     *
     * @return list<string>|null
     */
    private function splitPhones(string $cell): ?array
    {
        $phones = array_values(array_filter(
            array_map(trim(...), explode(';', $cell)),
            fn (string $p) => $p !== '' && ! in_array($p, ['—', '-', 'топилмади'], true),
        ));

        return $phones === [] ? null : $phones;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open {$path}");

            return null;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->error('Empty CSV');

            return null;
        }

        // Tolerate a UTF-8 BOM on the first header cell.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($h) => trim((string) $h), $header);
        $required = ['owner_name', 'owner_kind', 'owner_ref', 'district_code', 'address', 'lat', 'lng'];
        $missing = array_diff($required, $header);
        if ($missing !== []) {
            fclose($handle);
            $this->error('CSV is missing columns: '.implode(', ', $missing));

            return null;
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null]) {
                continue; // blank line
            }
            $assoc = [];
            foreach ($header as $index => $name) {
                $assoc[$name] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[$line] = $assoc;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Screens within `placements.group_radius_m` of each other share a group key
     * (same pole / two faces). Recomputed over the whole table — cheap at this
     * size and keeps keys stable across re-imports.
     */
    private function assignGroupKeys(): void
    {
        $radius = (float) config('placements.group_radius_m', 30);
        $all = AdPlacement::query()->orderBy('id')->get(['id', 'lat', 'lng']);
        $groupOf = [];

        foreach ($all as $i => $a) {
            foreach ($all as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                if ($this->distanceMeters((float) $a->lat, (float) $a->lng, (float) $b->lat, (float) $b->lng) <= $radius) {
                    $key = $groupOf[$a->id] ?? $groupOf[$b->id] ?? ('g'.$a->id);
                    $groupOf[$a->id] = $key;
                    $groupOf[$b->id] = $key;
                }
            }
        }

        foreach ($all as $placement) {
            $key = $groupOf[$placement->id] ?? null;
            AdPlacement::query()->whereKey($placement->id)->update(['group_key' => $key]);
        }
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
