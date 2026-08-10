<?php

namespace App\Http\Resources;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Region */
class AdminRegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ordersCount = (int) ($this->orders_as_region_count ?? 0)
            + (int) ($this->orders_as_district_count ?? 0);

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name_uz' => $this->name_uz,
            'name_ru' => $this->name_ru,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'code' => $this->parent->code,
                'name_uz' => $this->parent->name_uz,
                'name_ru' => $this->parent->name_ru,
            ] : null),
            'children_count' => $this->whenCounted('children'),
            'orders_count' => $this->when(
                isset($this->orders_as_region_count) || isset($this->orders_as_district_count),
                $ordersCount,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
