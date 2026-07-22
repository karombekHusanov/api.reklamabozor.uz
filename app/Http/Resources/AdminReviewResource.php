<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as shown in the admin moderation queue: rating + comment plus
 * who wrote it and which agency it targets.
 *
 * @mixin Review
 */
class AdminReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'client' => [
                'id' => $this->client?->id,
                'name' => trim(($this->client?->first_name ?? '').' '.($this->client?->last_name ?? '')),
            ],
            'agent' => [
                'id' => $this->agent?->id,
                'name' => trim(($this->agent?->first_name ?? '').' '.($this->agent?->last_name ?? '')),
                'company_name' => $this->agentProfile?->company_name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
