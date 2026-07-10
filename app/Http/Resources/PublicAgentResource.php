<?php

namespace App\Http\Resources;

use App\Models\AgentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Approved agent as shown publicly in the mini app (marketplace / home slider).
 * Exposes only presentation fields — no KYC / contact data.
 *
 * @mixin AgentProfile
 */
class PublicAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'company_logo' => $this->companyLogoFile?->url(),
            'bio' => $this->bio,
            'location_label' => $this->location_label,
            'lat' => $this->lat,
            'lng' => $this->lng,
            // Distance in metres from the requested point — only set by the "nearby" endpoint.
            'distance_m' => $this->when($this->distance_m !== null, fn () => $this->distance_m),
            'website_url' => $this->website_url,
            'completion_percent' => $this->completionPercent(),
            'completed_orders_count' => (int) ($this->completed_orders_count ?? 0),
            // Moderated review aggregates (avg is null until the first approved review).
            'rating_avg' => $this->approved_reviews_avg_rating !== null
                ? round((float) $this->approved_reviews_avg_rating, 1)
                : null,
            'rating_count' => (int) ($this->approved_reviews_count ?? 0),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
