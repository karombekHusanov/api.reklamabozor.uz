<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public client profile — aggregate trust signals for providers viewing a client.
 *
 * @mixin User
 */
class PublicClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'avatar' => $this->avatarFile?->url(),
            'is_verified' => $this->phone !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'total_orders' => (int) ($this->orders_count ?? 0),
            'in_progress_orders' => (int) ($this->in_progress_orders_count ?? 0),
            'completed_orders' => (int) ($this->completed_orders_count ?? 0),
            'cancelled_orders' => (int) ($this->cancelled_orders_count ?? 0),
            'rating_avg' => $this->approved_reviews_avg_rating !== null
                ? round((float) $this->approved_reviews_avg_rating, 1)
                : null,
            'rating_count' => (int) ($this->approved_reviews_count ?? 0),
        ];
    }
}
