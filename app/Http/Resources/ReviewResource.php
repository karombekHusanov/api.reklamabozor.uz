<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'direction' => $this->direction->value,
            'rating' => (float) $this->rating,
            'criteria' => $this->criteria,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'reviewer_id' => $this->reviewer_id,
            'reviewee_id' => $this->reviewee_id,
            'created_at' => $this->created_at,
        ];
    }
}
