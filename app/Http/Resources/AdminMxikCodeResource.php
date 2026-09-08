<?php

namespace App\Http\Resources;

use App\Models\MxikCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MxikCode */
class AdminMxikCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'package_code' => $this->package_code,
            'name_uz' => $this->name_uz,
            'name_ru' => $this->name_ru,
            'vat_rate' => $this->vat_rate,
            'unit' => $this->unit,
            'note' => $this->note,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            // Which service categories default to this code.
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name_uz' => $category->name_uz,
                'name_ru' => $category->name_ru,
                'type' => $category->type?->value,
            ])->all()),
            'created_at' => $this->created_at,
        ];
    }
}
