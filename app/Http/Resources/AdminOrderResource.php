<?php

namespace App\Http\Resources;

use App\Enums\OfferStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class AdminOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $client = $this->client;

        $acceptedOffer = $this->relationLoaded('offers')
            ? $this->offers->firstWhere('status', OfferStatus::Accepted)
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'status' => $this->status->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'tz_file' => $this->tzFile?->url(),
            // Set when the client sent the order directly to one agency.
            'target_agent' => $this->whenLoaded('targetAgent', fn () => $this->targetAgent ? [
                'id' => $this->targetAgent->id,
                'company_name' => $this->targetAgent->agentProfile?->company_name,
            ] : null),
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'username' => $client->username,
                'phone' => $client->phone,
                'telegram_id' => $client->telegram_id,
            ] : null,
            'offers' => AdminOfferResource::collection($this->whenLoaded('offers')),
            'accepted_offer_id' => $acceptedOffer?->id,
            'offers_count' => $this->whenCounted('offers'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
