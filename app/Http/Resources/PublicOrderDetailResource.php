<?php

namespace App\Http\Resources;

use App\Enums\AgentProfileStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full order detail for the public showcase — authenticated viewers see the
 * complete description, attachment files, and (for providers) their own offer
 * status + whether they can submit one.
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

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'attachment_files' => FileResource::collection(
                $this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [],
            ),
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
            $hasApproved = $user->providerProfiles()
                ->where('status', AgentProfileStatus::Approved)
                ->exists();

            return $hasApproved;
        }

        return false;
    }
}
