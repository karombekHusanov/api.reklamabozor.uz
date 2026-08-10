<?php

namespace App\Http\Resources;

use App\Models\OfferItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OfferItem */
class OfferItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->lineTotal(),
            'sort_order' => $this->sort_order,
        ];
    }
}
