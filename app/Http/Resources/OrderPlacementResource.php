<?php

namespace App\Http\Resources;

use App\Models\OrderPlacement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A screen picked for an order: the frozen snapshot plus live coordinates
 * when the placement is loaded.
 *
 * @mixin OrderPlacement
 */
class OrderPlacementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $placement = $this->relationLoaded('placement') ? $this->placement : null;

        return [
            'id' => $this->id,
            'placement_id' => $this->ad_placement_id,
            'address' => $this->address_snapshot,
            'owner_name' => $this->owner_name_snapshot,
            'kind' => $placement?->kind->value,
            'lat' => $placement?->lat,
            'lng' => $placement?->lng,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'slot_seconds' => $this->slot_seconds,
            'shows_per_hour' => $this->shows_per_hour,
            'date_from' => $this->date_from?->toDateString(),
            'date_to' => $this->date_to?->toDateString(),
            'estimated_price_som' => $this->estimated_price_tiyin === null ? null : intdiv($this->estimated_price_tiyin, 100),
            'sort_order' => $this->sort_order,
        ];
    }
}
