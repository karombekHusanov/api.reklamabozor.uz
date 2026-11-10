<?php

namespace App\Console\Commands;

use App\Enums\AdPlacementKind;
use App\Enums\AdPlacementStatus;
use App\Models\AdPlacement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load an owner's price list (one row per screen) onto the map: screen name,
 * size, audience figures and a monthly rate per clip length.
 *
 * CSV columns (header required): ref, name, tier, address_ru, lat, lng,
 * width_m, height_m, resolution, sides, shows_per_day, ots, traffic_class,
 * price_5s, price_10s, price_15s, price_20s (so'm per month, net of VAT),
 * coords_confirmed (1 = the map point is certainly this screen). The bundled
 * `database/data/ad_placements/medialux_led_2026.csv` joins Media Lux's 2026
 * price list (PDF) with the coordinates of their public Yandex map.
 *
 * Each row is attached to the owner's nearest already-imported screen within
 * `--match-radius` metres, otherwise a new placement is created (district taken
 * from the nearest known screen). Rows without coordinates are skipped. The
 * screen's monthly rates are replaced, so re-running is idempotent.
 */
class ImportPlacementRates extends Command
{
    private const SLOTS = [5, 10, 15, 20];

    protected $signature = 'placements:import-rates
        {path : CSV price list}
        {--owner=MEDIA LUX : owner_name_raw the price list belongs to}
        {--match-radius=60 : Metres within which a row is the same screen as an imported one}
        {--dry-run : Parse and report without writing}';

    protected $description = 'Import an owner price list (rates per clip length) for LED screens';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $rows = is_readable($path) ? $this->readCsv($path) : null;
        if ($rows === null) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $owner = (string) $this->option('owner');
        $radius = (float) $this->option('match-radius');
        $dryRun = (bool) $this->option('dry-run');
        $matched = $created = $skipped = $rates = 0;

        DB::transaction(function () use ($rows, $owner, $radius, $dryRun, &$matched, &$created, &$skipped, &$rates): void {
            $own = AdPlacement::query()->where('owner_name_raw', $owner)->orderBy('id')->get();
            $contacts = $own->first(fn (AdPlacement $p) => $p->owner_phones !== null || $p->owner_website !== null);
            $known = AdPlacement::query()->whereNotNull('region_id')->get(['id', 'region_id', 'lat', 'lng']);
            $taken = [];

            foreach ($rows as $line => $row) {
                $lat = (float) $row['lat'];
                $lng = (float) $row['lng'];
                if ($row['name'] === '' || $row['lat'] === '' || $row['lng'] === '') {
                    $this->warn("Line {$line}: \"{$row['name']}\" has no coordinates — skipped");
                    $skipped++;

                    continue;
                }

                $placement = $this->nearest($own->reject(fn (AdPlacement $p) => isset($taken[$p->id])), $lat, $lng, $radius);
                $specs = array_filter([
                    'name' => $row['name'],
                    'owner_code' => $row['ref'],
                    'tier' => $row['tier'],
                    'width_m' => (float) $row['width_m'] ?: null,
                    'height_m' => (float) $row['height_m'] ?: null,
                    'resolution' => $row['resolution'],
                    'sides' => (int) $row['sides'] ?: null,
                    'shows_per_day' => (int) $row['shows_per_day'] ?: null,
                    'ots' => (int) $row['ots'] ?: null,
                    'traffic_class' => $row['traffic_class'],
                    'coords_confirmed' => $row['coords_confirmed'] === '1',
                ], fn ($v) => $v !== null && $v !== '');

                if ($placement !== null) {
                    $matched++;
                    $taken[$placement->id] = true;
                    $placement->specs = [...($placement->specs ?? []), ...$specs];
                } else {
                    $created++;
                    $ref = $row['ref'] !== '' ? $row['ref'] : $row['name'];
                    $placement = new AdPlacement([
                        'kind' => AdPlacementKind::LedScreen,
                        'status' => AdPlacementStatus::Unclaimed,
                        'owner_name_raw' => $owner,
                        'owner_kind' => $contacts?->owner_kind ?? 'legal',
                        'external_ref' => mb_substr($ref, 0, 60),
                        'owner_phones' => $contacts?->owner_phones,
                        'owner_website' => $contacts?->owner_website,
                        'owner_email' => $contacts?->owner_email,
                        'import_key' => mb_substr($owner.'|price:'.$ref, 0, 260),
                        // District of the closest known screen; null outside the city.
                        'region_id' => $this->nearest($known, $lat, $lng, 1500)?->region_id,
                        'address' => mb_substr($row['address_ru'] !== '' ? $row['address_ru'] : $row['name'], 0, 300),
                        'lat' => $lat,
                        'lng' => $lng,
                        'specs' => $specs,
                        'source' => 'import',
                        'imported_at' => now(),
                    ]);
                }

                if ($dryRun) {
                    continue;
                }

                $placement->save();

                $placement->rates()->where('unit', 'month')->delete();
                foreach (self::SLOTS as $seconds) {
                    $price = (int) $row["price_{$seconds}s"];
                    if ($price <= 0) {
                        continue;
                    }
                    $placement->rates()->create([
                        'unit' => 'month',
                        'slot_seconds' => $seconds,
                        // The price list promises "from N shows a day", not an hourly loop.
                        'shows_per_hour' => 0,
                        'price_tiyin' => $price * 100,
                        'min_quantity' => 1,
                        'is_active' => true,
                    ]);
                    $rates++;
                }
            }
        });

        $this->info(sprintf(
            '%s%d matched, %d created, %d skipped, %d rates.',
            $dryRun ? '[dry-run] ' : '',
            $matched,
            $created,
            $skipped,
            $rates,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  iterable<AdPlacement>  $candidates
     */
    private function nearest(iterable $candidates, float $lat, float $lng, float $maxMeters): ?AdPlacement
    {
        $best = null;
        $bestDistance = $maxMeters;
        foreach ($candidates as $candidate) {
            $distance = $this->distanceMeters($lat, $lng, (float) $candidate->lat, (float) $candidate->lng);
            if ($distance <= $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'rb');
        $header = $handle === false ? false : fgetcsv($handle);
        if ($handle === false || $header === false) {
            return null;
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($h) => trim((string) $h), $header);
        $required = ['ref', 'name', 'lat', 'lng', 'price_5s', 'price_10s', 'price_15s', 'price_20s'];
        if (array_diff($required, $header) !== []) {
            fclose($handle);
            $this->error('CSV is missing columns: '.implode(', ', array_diff($required, $header)));

            return null;
        }

        $optional = ['tier', 'address_ru', 'width_m', 'height_m', 'resolution', 'sides', 'shows_per_day', 'ots', 'traffic_class', 'coords_confirmed'];
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $assoc = array_fill_keys($optional, '');
            foreach ($header as $index => $name) {
                $assoc[$name] = trim((string) ($cells[$index] ?? ''));
            }
            $rows[$line] = $assoc;
        }
        fclose($handle);

        return $rows;
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
