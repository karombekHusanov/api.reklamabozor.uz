<?php

namespace App\Http\Resources;

use App\Models\Offer;
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

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'price' => $this->price,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'agent' => [
                'id' => $this->agent_id,
                'profile_id' => $profile?->id,
                'company_name' => $profile?->company_name,
                'company_logo' => $profile?->companyLogoFile?->url(),
                'location_label' => $profile?->location_label,
                'person_type' => $this->agent?->effectivePersonType()?->value,
                'person_type_verified' => (bool) $this->agent?->isVerifiedLegalEntity(),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
