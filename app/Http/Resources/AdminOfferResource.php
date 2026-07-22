<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Offer as seen by an admin — includes the agency's contact details so the
 * manager can reach out.
 *
 * @mixin Offer
 */
class AdminOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $agentUser = $this->agent;
        // The specific profile that placed the offer (not just the user's).
        $profile = $this->agentProfile;

        return [
            'id' => $this->id,
            'price' => $this->price,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'agent' => [
                'id' => $this->agent_id,
                'company_name' => $profile?->company_name,
                'phone' => $profile?->phone,
                'location_label' => $profile?->location_label,
                'applicant_name' => $agentUser
                    ? trim(($agentUser->first_name ?? '').' '.($agentUser->last_name ?? ''))
                    : null,
                'username' => $agentUser?->username,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
