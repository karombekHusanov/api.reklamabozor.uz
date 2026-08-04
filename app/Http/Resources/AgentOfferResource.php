<?php

namespace App\Http\Resources;

use App\Enums\ReviewDirection;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agent's own offer, with a thumbnail of the order it belongs to.
 *
 * @mixin Offer
 */
class AgentOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myReview = null;
        if ($this->relationLoaded('order') && $this->order?->relationLoaded('reviews')) {
            $myReview = $this->order->reviews
                ->first(fn ($r) => $r->direction === ReviewDirection::ProviderToClient);
        }

        return [
            'id' => $this->id,
            'price' => $this->price,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'order' => [
                'id' => $this->order?->id,
                'title' => $this->order?->title,
                'status' => $this->order?->status->value,
                'category' => $this->order?->category
                    ? new CategoryResource($this->order->category)
                    : null,
            ],
            'my_review' => $myReview ? new ReviewResource($myReview) : null,
            'created_at' => $this->created_at,
        ];
    }
}
