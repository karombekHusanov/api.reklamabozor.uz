<?php

namespace App\Http\Resources;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Region */
class RegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_uz' => $this->name_uz,
            'name_ru' => $this->name_ru,
            'sort_order' => $this->sort_order,
            'districts' => $this->when(
                $this->relationLoaded('children'),
                fn () => $this->children
                    ->sortBy(['sort_order', 'id'])
                    ->values()
                    ->map(fn (Region $district) => [
                        'id' => $district->id,
                        'code' => $district->code,
                        'name_uz' => $district->name_uz,
                        'name_ru' => $district->name_ru,
                        'sort_order' => $district->sort_order,
                    ]),
            ),
        ];
    }
}
