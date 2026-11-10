<?php

namespace Database\Seeders;

use App\Models\AdFormat;
use Illuminate\Database\Seeder;

/**
 * Metro advertising formats from the operator's price list. Idempotent on
 * `code` — re-run after editing the data file to refresh prices and counts.
 */
class AdFormatSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/ad_formats/metro_transgroup_2026.php');

        foreach ($data['formats'] as $index => $row) {
            $rates = [];
            foreach ($row['rates'] as $seconds => $som) {
                $rates[] = ['slot_seconds' => $seconds, 'price_tiyin' => $som * 100];
            }

            AdFormat::query()->updateOrCreate(['code' => $row['code']], [
                'venue' => 'metro',
                'medium' => $row['medium'],
                'name_uz' => $row['name_uz'],
                'name_ru' => $row['name_ru'],
                'specs' => $row['specs'],
                'total_count' => array_sum($row['lines']),
                'line_counts' => $row['lines'],
                'is_package' => $row['package'] ?? false,
                'min_months' => $row['min_months'] ?? 1,
                'production_included' => $row['medium'] === AdFormat::MEDIUM_DIGITAL,
                'daily_reach' => $row['reach'] ?? null,
                'rates' => $rates,
                'owner_name' => $data['owner']['name'],
                'owner_phones' => $data['owner']['phones'],
                'owner_website' => $data['owner']['website'],
                'sort_order' => $index,
            ]);
        }
    }
}
