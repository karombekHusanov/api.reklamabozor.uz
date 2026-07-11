<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as presented to an agent browsing opportunities. Includes the
 * agent's own offer (when the `offers` relation was constrained to them).
 *
 * @mixin Order
 */
class AgentOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myOffer = $this->relationLoaded('offers') ? $this->offers->first() : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'attachment_files' => FileResource::collection(
                $this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [],
            ),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'status' => $this->status->value,
            'views_count' => $this->whenCounted('views'),
            'offers_count' => $this->whenCounted('offers'),
            'client' => [
                'first_name' => $this->client?->first_name,
            ],
            'my_offer' => $myOffer ? [
                'id' => $myOffer->id,
                'price' => $myOffer->price,
                'comment' => $myOffer->comment,
                'status' => $myOffer->status->value,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
