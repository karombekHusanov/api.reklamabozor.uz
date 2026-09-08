<?php

namespace App\Http\Resources;

use App\Enums\ReviewDirection;
use App\Models\ContractAcceptance;
use App\Models\Offer;
use App\Services\Chat\DirectChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full offer detail for the owning agent — includes the order body, client,
 * and attachments so the mini app can render a dedicated detail page.
 *
 * @mixin Offer
 */
class AgentOfferDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->order;

        $myReview = null;
        if ($order?->relationLoaded('reviews')) {
            $myReview = $order->reviews
                ->first(fn ($r) => $r->direction === ReviewDirection::ProviderToClient);
        }

        $chat = app(DirectChatService::class)->findForOffer($this->resource);

        return [
            'id' => $this->id,
            'price' => $this->price,
            'comment' => $this->comment,
            'deadline_days' => $this->deadline_days,
            'status' => $this->status->value,
            'is_interest' => $this->isInterest(),
            'can_accept' => $this->canAccept(),
            'items' => OfferItemResource::collection($this->whenLoaded('items')),
            'price_updated_at' => $this->price_updated_at,
            'price_edit_count' => (int) $this->price_edit_count,
            'price_edits_remaining' => $this->priceEditsRemaining(),
            'max_price_edits' => Offer::MAX_PRICE_EDITS,
            'can_edit_price' => $this->canEditPrice(),
            'contract' => [
                'agent_accepted_at' => $this->contractAcceptedAt(ContractAcceptance::PARTY_AGENT),
                'client_accepted_at' => $this->contractAcceptedAt(ContractAcceptance::PARTY_CLIENT),
            ],
            'chat_id' => $chat?->id,
            'chat' => $chat ? ['id' => $chat->id, 'blocked' => $chat->isBlocked()] : null,
            'my_review' => $myReview ? new ReviewResource($myReview) : null,
            'created_at' => $this->created_at,
            'order' => $order ? [
                'id' => $order->id,
                'title' => $order->title,
                'description' => $order->description,
                'deadline' => $order->deadline?->value,
                'lat' => $order->lat,
                'lng' => $order->lng,
                'location_label' => $order->location_label,
                'status' => $order->status->value,
                'category' => $order->relationLoaded('category') && $order->category
                    ? new CategoryResource($order->category)
                    : null,
                'region' => $order->relationLoaded('region') && $order->region
                    ? new RegionResource($order->region)
                    : null,
                'district' => $order->relationLoaded('district') && $order->district
                    ? new RegionResource($order->district)
                    : null,
                'hashtags' => HashtagResource::collection(
                    $order->relationLoaded('hashtags') ? $order->hashtags : [],
                ),
                'attachment_files' => FileResource::collection(
                    $order->relationLoaded('attachmentFiles') ? $order->attachmentFiles : [],
                ),
                'activated_at' => $order->activated_at,
                'outstanding_som' => round($order->outstandingTiyin() / 100, 2),
                'amendment_window' => [
                    'can_propose' => $order->canProposeAmendment($request->user()),
                    'reason' => $order->amendmentProposalState($request->user()),
                    'ends_at' => $order->amendmentWindowEndsAt(),
                ],
                'views_count' => $order->views_count ?? null,
                'offers_count' => $order->offers_count ?? null,
                'contract' => $order->relationLoaded('contract') && $order->contract
                    ? new ContractResource($order->contract)
                    : null,
                'client' => [
                    'id' => $order->client?->id,
                    'first_name' => $order->client?->first_name,
                    'avatar' => $order->client?->avatarFile?->url(),
                ],
                'created_at' => $order->created_at,
            ] : null,
        ];
    }
}
