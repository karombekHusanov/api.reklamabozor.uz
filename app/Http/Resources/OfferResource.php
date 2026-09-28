<?php

namespace App\Http\Resources;

use App\Models\ContractAcceptance;
use App\Models\Offer;
use App\Services\Chat\DirectChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Offer */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The specific profile that placed the offer (not just the user's).
        $profile = $this->agentProfile;
        $rating = $profile?->cachedRating;
        $chat = app(DirectChatService::class)->findForOffer($this->resource);
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'price' => $this->price,
            'comment' => $this->comment,
            'deadline_days' => $this->deadline_days,
            'status' => $this->status->value,
            'is_interest' => $this->isInterest(),
            'can_accept' => $this->canAccept(),
            'items' => OfferItemResource::collection($this->whenLoaded('items')),
            'price_updated_at' => $this->price_updated_at,
            'agent' => [
                'id' => $this->agent_id,
                'profile_id' => $profile?->id,
                'provider_type' => $profile?->provider_type?->value,
                'company_name' => $profile?->company_name,
                'company_logo' => $profile?->companyLogoFile?->url(),
                // Individuals (designers) often have no logo — the Telegram avatar stands in.
                'avatar' => $this->agent?->avatarFile?->url(),
                'location_label' => $profile?->location_label,
                'stars' => $rating?->stars !== null ? (float) $rating->stars : null,
                'stars_count' => (int) ($rating?->stars_count ?? 0),
                'person_type' => $this->agent?->effectivePersonType()?->value,
                'person_type_verified' => (bool) $this->agent?->isVerifiedLegalEntity(),
            ],
            // Click-wrap contract consent (agent signs by sending the pricelist,
            // client by accepting the offer).
            'contract' => [
                'agent_accepted_at' => $this->contractAcceptedAt(ContractAcceptance::PARTY_AGENT),
                'client_accepted_at' => $this->contractAcceptedAt(ContractAcceptance::PARTY_CLIENT),
            ],
            'chat_id' => $chat?->id,
            // Order thread preview for the offers list — only for its participants.
            'chat' => $chat !== null && $viewer !== null && $chat->isParticipant($viewer) ? [
                'last_message' => $chat->lastMessage ? [
                    'body' => $chat->lastMessage->body,
                    'type' => $chat->lastMessage->type,
                    'mine' => $chat->lastMessage->sender_id === $viewer->id,
                    'created_at' => $chat->lastMessage->created_at,
                ] : null,
                'unread_count' => $chat->unreadCountFor($viewer),
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
