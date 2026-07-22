<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telegram_id' => $this->telegram_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_file_id' => $this->avatar_file_id,
            'avatar' => $this->avatarFile?->url(),
            // Active role + every role the user holds (multirole).
            'role' => $this->role->value,
            'roles' => $this->allRoles()->map(fn ($role) => $role->value)->all(),
            'role_selected_at' => $this->role_selected_at,
            // Legal nature: effective type (derived for agents/sellers) + whether
            // it is confirmed. `person_type_declared` is the raw self-choice, so
            // the client/designer onboarding can tell "not asked yet" from a pick.
            'person_type' => $this->effectivePersonType()?->value,
            'person_type_verified' => $this->isVerifiedLegalEntity(),
            'person_type_declared' => $this->person_type?->value,
            // Verification state for the LinkedIn-style badge/CTA: pending /
            // approved / rejected, or null when nothing has been submitted.
            'legal_entity_status' => $this->legalEntityStatus()?->value,
            // KYC application status; null = agent-role user who never applied.
            'agent_profile_status' => $this->whenLoaded(
                'agentProfile',
                fn () => $this->agentProfile?->status->value,
                null,
            ),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
