<?php

namespace App\Http\Resources;

use App\Models\AgentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentProfile */
class AgentProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Verification / KYC (Phase 1).
            'company_name' => $this->company_name,
            'legal_form' => $this->legal_form,
            'inn' => $this->inn,
            'director_name' => $this->director_name,
            'director_passport' => $this->director_passport,
            'director_passport_file_id' => $this->director_passport_file_id,
            'director_passport_file' => $this->directorPassportFile?->url(),
            'registration_certificate_file_id' => $this->registration_certificate_file_id,
            'registration_certificate_file' => $this->registrationCertificateFile?->url(),
            'bank_name' => $this->bank_name,
            'bank_account' => $this->bank_account,
            'mfo' => $this->mfo,
            'phone' => $this->phone,

            // Presentation (Phase 2).
            'company_logo_file_id' => $this->company_logo_file_id,
            'company_logo' => $this->companyLogoFile?->url(),
            'bio' => $this->bio,
            'linkedin_url' => $this->linkedin_url,
            'website_url' => $this->website_url,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'location_label' => $this->location_label,
            'results_text' => $this->results_text,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'advantages' => AdvantageResource::collection($this->whenLoaded('advantages')),
            'portfolio' => AgentPortfolioItemResource::collection($this->whenLoaded('portfolioItems')),
            'workflow_steps' => $this->workflow_steps ?? [],
            'completion_percent' => $this->completionPercent(),

            // Status.
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
