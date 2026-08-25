<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Anonymised order for the public home "live orders" showcase — social proof of
 * marketplace activity. Exposes category, region, a short teaser, the client
 * preview, and public counters (views / offers / attachment count). Omits
 * attachment payloads, budget and contact data.
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
            'region' => new RegionResource($this->whenLoaded('region')),
            'district' => new RegionResource($this->whenLoaded('district')),
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            'status' => $this->status->value,
            'views_count' => (int) ($this->views_count ?? 0),
            'offers_count' => (int) ($this->offers_count ?? 0),
            'attachments_count' => count($this->allAttachmentFileIds()),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'first_name' => $this->client->first_name,
                'avatar' => $this->client->avatarFile?->url(),
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
