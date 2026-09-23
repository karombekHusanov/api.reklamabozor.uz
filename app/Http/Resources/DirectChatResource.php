<?php

namespace App\Http\Resources;

use App\Enums\OfferStatus;
use App\Models\DirectChat;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Client ↔ agency direct conversation as seen by one participant.
 *
 * Marketplace DM: order_id / order are null.
 * Order-scoped negotiation: order_id + nested order summary are set.
 *
 * @mixin DirectChat
 */
class DirectChatResource extends JsonResource
{
    /** Optional pending-offer context for the thread detail screen. */
    protected ?Offer $activeOffer = null;

    protected bool $includeActiveOffer = false;

    public function withActiveOffer(?Offer $offer): static
    {
        $this->activeOffer = $offer;
        $this->includeActiveOffer = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $other = $this->otherParticipant($user);

        // The agency identity belongs to the chat's linked profile, shown only
        // when the other side is the agent (the client has no provider profile).
        $agentProfile = $other->id === $this->agent_id ? $this->agentProfile : null;

        $order = $this->relationLoaded('order') ? $this->order : null;

        $data = [
            'id' => $this->id,
            'type' => 'direct',
            'order_id' => $this->order_id,
            'order' => $this->order_id !== null ? [
                'id' => $order?->id ?? $this->order_id,
                'title' => $order?->title,
                'status' => $order?->status?->value,
                'category' => $order?->relationLoaded('category') && $order->category
                    ? new CategoryResource($order->category)
                    : null,
            ] : null,
            'other_participant' => [
                'id' => $other->id,
                'name' => trim($other->first_name.' '.($other->last_name ?? '')),
                'company_name' => $agentProfile?->company_name,
                'agent_profile_id' => $agentProfile?->id,
            ],
            'last_message' => $this->whenLoaded('lastMessage', fn () => $this->lastMessage
                ? new DirectChatMessageResource($this->lastMessage)
                : null),
            'unread_count' => (int) $this->unreadCountFor($user),
            'blocked_at' => $this->blocked_at,
            'blocked_by' => $this->blocked_by,
            'can_write' => $this->canWrite($user),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($this->includeActiveOffer) {
            // Thread detail only (lists skip the extra query).
            $data['other_participant']['phone'] = $this->clientPhoneFor($user, $other);

            $offer = $this->activeOffer;
            $data['active_offer'] = $offer ? [
                'id' => $offer->id,
                'order_id' => $offer->order_id,
                'order_title' => $offer->order?->title,
                'price' => $offer->price,
                'status' => $offer->status->value,
                'is_interest' => $offer->isInterest(),
                'can_edit_price' => $offer->canEditPrice(),
                'price_edits_remaining' => $offer->priceEditsRemaining(),
                'max_price_edits' => Offer::MAX_PRICE_EDITS,
            ] : null;
        }

        return $data;
    }

    /**
     * The client's phone, for the agent only, and only in an order thread
     * where the agent's otklik/offer is still live (pending or accepted) — a
     * withdrawn, released or rejected response hides it again. Never exposed
     * in a plain marketplace DM or to the client.
     */
    private function clientPhoneFor(User $viewer, User $other): ?string
    {
        if ($this->order_id === null || $viewer->id !== $this->agent_id || $other->id !== $this->client_id) {
            return null;
        }

        $live = Offer::query()
            ->where('order_id', $this->order_id)
            ->where('agent_id', $viewer->id)
            ->whereIn('status', [OfferStatus::Pending, OfferStatus::Accepted])
            ->exists();

        return $live ? $other->phone : null;
    }
}
