<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_uz' => $this->name_uz,
            'name_ru' => $this->name_ru,
            'parent_id' => $this->parent_id,
            'depth' => (int) $this->depth,
            // Root → … → this node (ids), for client-side breadcrumbs.
            'path_ids' => $this->ancestorIdsWithSelf(),
            'full_name_uz' => $this->fullName('uz'),
            'full_name_ru' => $this->fullName('ru'),
            'type' => $this->type->value,
            'image_file_id' => $this->image_file_id,
            'image' => $this->imageFile?->url(),
            'is_active' => $this->is_active,
            'is_other' => (bool) $this->is_other,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Nested sub-categories — only on tree endpoints.
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
