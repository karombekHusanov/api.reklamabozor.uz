<?php

namespace App\Http\Resources;

use App\Models\AdFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdFormat
 */
class AdFormatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $minMonth = $this->minMonthPriceTiyin();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'venue' => $this->venue,
            'medium' => $this->medium,
            'name_uz' => $this->name_uz,
            'name_ru' => $this->name_ru,
            'specs' => $this->specs ?? [],
            'total_count' => $this->total_count,
            'line_counts' => $this->line_counts ?? [],
            'is_package' => $this->is_package,
            'min_months' => $this->min_months,
            'production_included' => $this->production_included,
            'daily_reach' => $this->daily_reach,
            'slot_options' => $this->slotOptions(),
            'rates' => array_map(fn (array $rate) => [
                'slot_seconds' => (int) $rate['slot_seconds'],
                'price_som' => intdiv((int) $rate['price_tiyin'], 100),
            ], $this->rates ?? []),
            'min_month_price_som' => $minMonth === null ? null : intdiv($minMonth, 100),
            'owner' => [
                'name' => $this->owner_name,
                'phones' => $this->owner_phones ?? [],
                'website' => $this->owner_website,
            ],
        ];
    }
}
