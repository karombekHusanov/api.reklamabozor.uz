<?php

namespace App\Http\Resources;

use App\Enums\ReviewDirection;
use App\Models\Offer;
use App\Services\Chat\DirectChatService;
use App\Services\Order\PassGate;
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
            'is_interest' => $this->isInterest(),
            'can_accept' => $this->canAccept(),
            'can_withdraw' => $this->canWithdraw(),
            // Tezkor pay_on_pick: the client picked this otklik — pay to take it
            // before `expires_at`, or the client picks again.
            'pick' => $this->pickPayload(),
            'items' => OfferItemResource::collection($this->whenLoaded('items')),
            'price_updated_at' => $this->price_updated_at,
            'price_edit_count' => (int) $this->price_edit_count,
            'price_edits_remaining' => $this->priceEditsRemaining(),
            'max_price_edits' => Offer::MAX_PRICE_EDITS,
            'can_edit_price' => $this->canEditPrice(),
            'chat_id' => app(DirectChatService::class)->findForOffer($this->resource)?->id,
            'order' => [
                'id' => $this->order?->id,
                'title' => $this->order?->title,
                'description' => $this->order?->description,
                'status' => $this->order?->status->value,
                // Tezkor has no pricelist/contract/payment — the UI switches on this.
                'billing' => $this->order ? app(PassGate::class)->billingFor($this->order) : null,
                'route' => $this->order?->route->value,
                'category' => $this->order?->category
                    ? new CategoryResource($this->order->category)
                    : null,
                'hashtags' => HashtagResource::collection(
                    $this->order?->relationLoaded('hashtags') ? $this->order->hashtags : [],
                ),
                'views_count' => $this->order?->views_count ?? null,
                'offers_count' => $this->order?->offers_count ?? null,
                'client' => $this->order ? [
                    'id' => $this->order->client?->id,
                    'first_name' => $this->order->client?->first_name,
                    'avatar' => $this->order->client?->avatarFile?->url(),
                ] : null,
                'created_at' => $this->order?->created_at,
            ],
            'my_review' => $myReview ? new ReviewResource($myReview) : null,
            'created_at' => $this->created_at,
        ];
    }

    /** @return array{picked_at: mixed, expires_at: mixed, fee_som: int}|null */
    private function pickPayload(): ?array
    {
        $order = $this->order;

        if ($order === null || $order->picked_offer_id !== $this->id || ! $order->hasActivePick()) {
            return null;
        }

        return [
            'picked_at' => $order->picked_at,
            'expires_at' => $order->pick_expires_at,
            'fee_som' => intdiv(app(PassGate::class)->feeTiyin($order), 100),
        ];
    }
}
