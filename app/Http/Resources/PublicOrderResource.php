<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Anonymised order for the public home "live orders" showcase — social proof of
 * marketplace activity. Deliberately omits client identity, attachments, budget
 * and any contact data; it exposes only the category, a short teaser and the
 * public activity counters (views / offers).
 *
 * @mixin Order
 */
class PublicOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => Str::limit((string) $this->description, 120),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'status' => $this->status->value,
            'views_count' => (int) ($this->views_count ?? 0),
            'offers_count' => (int) ($this->offers_count ?? 0),
            'created_at' => $this->created_at,
        ];
    }
}
