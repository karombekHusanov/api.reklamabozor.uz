<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\Chat\DirectChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full order detail for the public showcase — authenticated viewers see the
 * complete description, and (for providers) their own offer status + whether
 * they can submit one. Attachment files are included only when the order's
 * `show_files_in_showcase` flag is true; otherwise the key is an empty array.
 *
 * @mixin Order
 */
class PublicOrderDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $myOffer = $this->relationLoaded('offers') ? $this->offers->first() : null;

        $attachmentFiles = ($this->show_files_in_showcase ?? true)
            ? ($this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [])
            : [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'region' => new RegionResource($this->whenLoaded('region')),
            'district' => new RegionResource($this->whenLoaded('district')),
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            'attachment_files' => FileResource::collection($attachmentFiles),
            'status' => $this->status->value,
            'views_count' => (int) ($this->views_count ?? 0),
            'offers_count' => (int) ($this->offers_count ?? 0),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'first_name' => $this->client->first_name,
                'avatar' => $this->client->avatarFile?->url(),
            ]),
            'my_offer' => $myOffer ? [
                'id' => $myOffer->id,
                'price' => $myOffer->price,
                'comment' => $myOffer->comment,
                'status' => $myOffer->status->value,
                'is_interest' => $myOffer->isInterest(),
                'can_accept' => $myOffer->canAccept(),
                'chat_id' => app(DirectChatService::class)->findForOffer($myOffer)?->id,
            ] : null,
            'can_offer' => $this->resolveCanOffer($user),
            'created_at' => $this->created_at,
        ];
    }

    private function resolveCanOffer(?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        /** @var Order $order */
        $order = $this->resource;

        if (! $order->status->isOpenForOffers()) {
            return false;
        }

        // Mirror the submit-side guards so the showcase CTA never promises an
        // offer the server would reject. One account can be both client and
        // provider (1 user = 1 profile), so exclude the viewer's own order...
        if ($order->client_id === $user->id) {
            return false;
        }

        // ...and directed orders, which only the addressed agency may answer.
        if ($order->target_agent_id !== null && $order->target_agent_id !== $user->id) {
            return false;
        }

        $myOffer = $this->relationLoaded('offers') ? $this->offers->first() : null;
        if ($myOffer !== null) {
            return false;
        }

        $profile = $user->providerProfileForCategory($order->category_id);
        if ($profile !== null) {
            return true;
        }

        $order->loadMissing('category');
        if ($order->category?->shouldBroadcastToAllProviders()) {
            return $user->approvedProfile() !== null;
        }

        return false;
    }
}
