<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\Chat\DirectChatService;
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
            'region' => new RegionResource($this->whenLoaded('region')),
            'district' => new RegionResource($this->whenLoaded('district')),
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            'attachment_files' => FileResource::collection(
                $this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [],
            ),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'location_label' => $this->location_label,
            'status' => $this->status->value,
            'route' => $this->route->value,
            // Tezkor: the client picked this agent ("Kelishildi").
            'claimed_by_me' => $this->isTezkor() && $this->claimed_agent_id === $request->user()?->id,
            'can_offer' => $this->canOffer($request, $myOffer),
            'views_count' => $this->whenCounted('views'),
            'offers_count' => $this->whenCounted('offers'),
            'client' => [
                'id' => $this->client?->id,
                'first_name' => $this->client?->first_name,
                'avatar' => $this->client?->avatarFile?->url(),
                // Contact is revealed only to the agent the client picked.
                'phone' => $this->isTezkor() && $this->claimed_agent_id === $request->user()?->id
                    ? $this->client?->phone : null,
                'username' => $this->isTezkor() && $this->claimed_agent_id === $request->user()?->id
                    ? $this->client?->username : null,
            ],
            'my_offer' => $myOffer ? [
                'id' => $myOffer->id,
                'price' => $myOffer->price,
                'comment' => $myOffer->comment,
                'status' => $myOffer->status->value,
                'is_interest' => $myOffer->isInterest(),
                'can_accept' => $myOffer->canAccept(),
                'items' => OfferItemResource::collection($myOffer->relationLoaded('items') ? $myOffer->items : []),
                'chat_id' => app(DirectChatService::class)->findForOffer($myOffer)?->id,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }

    private function canOffer(Request $request, mixed $myOffer): bool
    {
        /** @var Order $order */
        $order = $this->resource;

        return $myOffer === null && $order->status->isOpenForOffers();
    }
}
