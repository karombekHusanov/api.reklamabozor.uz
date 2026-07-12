<?php

namespace App\Http\Resources;

use App\Models\AgentPortfolioItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentPortfolioItem */
class AgentPortfolioItemResource extends JsonResource
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
            'link_url' => $this->link_url,
            // Cover image — first gallery image (backward compatible).
            'image' => $this->imageFile?->url(),
            'images' => FileResource::collection(
                $this->relationLoaded('imageFiles') ? $this->imageFiles : [],
            ),
            'attachments' => FileResource::collection(
                $this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [],
            ),
            'sort_order' => $this->sort_order,
            // Owner + admin surfaces see the takedown state; public payloads
            // only ever contain visible items, so the flag is harmless there.
            'is_hidden' => $this->hidden_at !== null,
            'created_at' => $this->created_at,
        ];
    }
}
