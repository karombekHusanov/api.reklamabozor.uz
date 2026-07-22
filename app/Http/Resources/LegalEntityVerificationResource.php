<?php

namespace App\Http\Resources;

use App\Models\LegalEntityVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegalEntityVerification */
class LegalEntityVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inn' => $this->inn,
            'company_name' => $this->company_name,
            'registration_certificate' => $this->registrationCertificateFile?->url(),
            'registration_certificate_file_id' => $this->registration_certificate_file_id,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'verified_at' => $this->verified_at,
            // Present in the admin moderation queue (user eager-loaded).
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => trim($this->user->first_name.' '.($this->user->last_name ?? '')),
                'phone' => $this->user->phone,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
