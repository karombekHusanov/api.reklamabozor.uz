<?php

namespace App\Http\Resources;

use App\Models\UserRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserRating */
class UserRatingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'role' => $this->role->value,
            'agent_profile_id' => $this->agent_profile_id,
            'stars' => (float) $this->stars,
            'stars_count' => $this->stars_count,
            'grade' => $this->grade,
            'listing_boost' => $this->listing_boost,
            // Backward-compat aliases
            'rating_avg' => $this->stars_count > 0 ? (float) $this->stars : null,
            'rating_count' => $this->stars_count,
        ];
    }
}
