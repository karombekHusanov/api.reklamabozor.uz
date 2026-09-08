<?php

namespace App\Http\Resources;

use App\Models\ContractAcceptance;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Offer as seen by an admin — includes the agency's contact details so the
 * manager can reach out, plus the click-wrap contract acceptances (who
 * confirmed the three-party contract, when, and from where).
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
            'is_interest' => $this->isInterest(),
            'can_accept' => $this->canAccept(),
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
            'contract_acceptances' => $this->acceptances(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Latest acceptance per party, newest first — the audit trail a manager
     * needs when a deal is disputed.
     *
     * @return list<array<string, mixed>>
     */
    private function acceptances(): array
    {
        $rows = $this->relationLoaded('contractAcceptances')
            ? $this->contractAcceptances
            : $this->contractAcceptances()->with('user')->get();

        return $rows
            ->groupBy('party')
            ->map(fn ($partyRows) => $partyRows->first())
            ->sortByDesc('accepted_at')
            ->values()
            ->map(function (ContractAcceptance $acceptance): array {
                $user = $acceptance->user;

                return [
                    'party' => $acceptance->party,
                    'accepted_at' => $acceptance->accepted_at,
                    'total' => $acceptance->total,
                    'version' => $acceptance->version,
                    'terms_version' => $acceptance->terms_version,
                    'hash' => $acceptance->hash,
                    'ip_address' => $acceptance->ip_address,
                    'user' => [
                        'id' => $acceptance->user_id,
                        'name' => $user
                            ? trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
                            : null,
                        'username' => $user?->username,
                    ],
                ];
            })
            ->all();
    }
}
