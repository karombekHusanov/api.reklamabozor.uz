<?php

namespace App\Http\Resources;

use App\Enums\AgentProfileStatus;
use App\Enums\AgentProfileUpgradeStatus;
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
        // Individual ("jismoniy") executors have no legal-entity requisites —
        // those fields are hidden (null) and only the light passport+selfie KYC
        // plus contact details are shown.
        $individual = $this->isIndividual();

        return [
            'id' => $this->id,
            'is_individual' => $individual,

            // Verification / KYC (Phase 1).
            'company_name' => $this->company_name,
            'legal_form' => $individual ? null : $this->legal_form,
            'inn' => $individual ? null : $this->inn,
            'legal_address' => $individual ? null : $this->legal_address,
            'director_pinfl' => $individual ? null : $this->director_pinfl,
            'director_position' => $individual ? null : $this->director_position,
            // For individuals this is their full name.
            'director_name' => $this->director_name,
            'director_passport' => $individual ? null : $this->director_passport,
            'director_passport_file_id' => $this->director_passport_file_id,
            'director_passport_file' => $this->directorPassportFile?->url(),
            'selfie_file_id' => $this->selfie_file_id,
            'selfie' => $this->selfieFile?->url(),
            'registration_certificate_file_id' => $individual ? null : $this->registration_certificate_file_id,
            'registration_certificate_file' => $individual ? null : $this->registrationCertificateFile?->url(),
            'bank_name' => $individual ? null : $this->bank_name,
            'bank_account' => $individual ? null : $this->bank_account,
            'mfo' => $individual ? null : $this->mfo,
            'phone' => $this->phone,
            'alt_contact' => $this->alt_contact,

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
            'display_name' => $this->company_name
                ?: trim($this->user->first_name.' '.($this->user->last_name ?? '')),
            'provider_type' => $this->provider_type->value,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'advantages' => AdvantageResource::collection($this->whenLoaded('advantages')),
            'portfolio' => AgentPortfolioItemResource::collection($this->whenLoaded('portfolioItems')),
            'workflow_steps' => $this->workflow_steps ?? [],
            'completion_percent' => $this->completionPercent(),

            // Status.
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at,

            // Platform agreement (agent signs offline + re-uploads).
            // Agency partnership offer (click-wrap at KYC submit).
            'offer' => $this->requiresContract() ? [
                'version' => (string) config('legal.agent_offer_version'),
                'accepted_version' => $this->offer_version,
                'accepted_at' => $this->offer_accepted_at,
                'needs_acceptance' => ! $this->hasAcceptedCurrentOffer(),
            ] : null,

            'contract' => $this->requiresContract() ? [
                'status' => $this->contract_status?->value,
                'version' => $this->contract_version,
                'file' => $this->contractFile?->url(),
                'signed_file' => $this->signedContractFile?->url(),
                'rejection_reason' => $this->contract_rejection_reason,
                'generated_at' => $this->contract_generated_at,
                'signed_at' => $this->contract_signed_at,
                'approved' => $this->contractApproved(),
            ] : null,

            // Individual → legal-entity track request (latest, any status).
            // `can_request`: an approved individual with nothing pending.
            'upgrade' => [
                'can_request' => $this->isIndividual()
                    && $this->status === AgentProfileStatus::Approved
                    && $this->latestUpgrade?->status !== AgentProfileUpgradeStatus::Pending,
                'latest' => $this->latestUpgrade ? [
                    'id' => $this->latestUpgrade->id,
                    'status' => $this->latestUpgrade->status->value,
                    'rejection_reason' => $this->latestUpgrade->rejection_reason,
                    'submitted_at' => $this->latestUpgrade->updated_at,
                    'reviewed_at' => $this->latestUpgrade->reviewed_at,
                ] : null,
            ],

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
