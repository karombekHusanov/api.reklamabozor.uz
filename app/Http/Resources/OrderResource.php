<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'tz_file_id' => $this->tz_file_id,
            'tz_file' => $this->tzFile?->url(),
            'attachment_file_ids' => $this->attachment_file_ids ?? [],
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'status' => $this->status->value,
            'work_submitted_at' => $this->work_submitted_at,
            'completed_at' => $this->completed_at,
            'auto_completed' => $this->auto_completed,
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'offers_count' => $this->whenCounted('offers'),
            'views_count' => $this->whenCounted('views'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
