<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;

/**
 * Tap-sheet / detail shape: the list fields plus specs, photos and rate card.
 */
class PlacementDetailResource extends PlacementResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $photoIds = $this->photo_file_ids ?? [];
        $photos = $photoIds === []
            ? []
            : File::query()->whereIn('id', $photoIds)->get()->map(fn (File $f) => $f->url())->filter()->values()->all();

        return [
            ...parent::toArray($request),
            'external_ref' => $this->external_ref,
            'specs' => $this->specs ?? [],
            'slot_seconds' => $this->slot_seconds,
            'shows_per_hour' => $this->shows_per_hour,
            'photos' => $photos,
            'rates' => ($this->relationLoaded('activeRates') ? $this->activeRates : collect())->map(fn ($rate) => [
                'id' => $rate->id,
                'unit' => $rate->unit,
                'slot_seconds' => $rate->slot_seconds,
                'shows_per_hour' => $rate->shows_per_hour,
                'price_tiyin' => $rate->price_tiyin,
                'price_som' => intdiv($rate->price_tiyin, 100),
                'min_quantity' => $rate->min_quantity,
            ])->values()->all(),
            'verified_at' => $this->verified_at,
        ];
    }
}
