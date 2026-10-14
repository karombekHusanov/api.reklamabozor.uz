<?php

namespace App\Http\Resources;

use App\Models\AgentProfileUpgrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentProfileUpgrade
 */
class AgentProfileUpgradeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_profile_id' => $this->agent_profile_id,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,

            'company_name' => $this->company_name,
            'legal_form' => $this->legal_form,
            'inn' => $this->inn,
            'legal_address' => $this->legal_address,
            'director_name' => $this->director_name,
            'director_pinfl' => $this->director_pinfl,
            'director_position' => $this->director_position,
            'director_passport' => $this->director_passport,
            'director_passport_file_id' => $this->director_passport_file_id,
            'director_passport_file' => $this->directorPassportFile?->url(),
            'registration_certificate_file_id' => $this->registration_certificate_file_id,
            'registration_certificate_file' => $this->registrationCertificateFile?->url(),
            'bank_name' => $this->bank_name,
            'bank_account' => $this->bank_account,
            'mfo' => $this->mfo,
            'phone' => $this->phone,

            'offer_version' => $this->offer_version,
            'offer_accepted_at' => $this->offer_accepted_at,

            // Present in the admin queue (eager-loaded there).
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => trim($this->user->first_name.' '.($this->user->last_name ?? '')),
                'phone' => $this->user->phone,
            ]),
            'agent_profile' => $this->whenLoaded('agentProfile', fn () => [
                'id' => $this->agentProfile->id,
                'provider_type' => $this->agentProfile->provider_type->value,
                'status' => $this->agentProfile->status->value,
                // The individual's own name on the light KYC, for comparison.
                'full_name' => $this->agentProfile->director_name,
            ]),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
