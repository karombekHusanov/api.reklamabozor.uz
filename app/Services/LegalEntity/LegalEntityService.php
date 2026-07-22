<?php

namespace App\Services\LegalEntity;

use App\Enums\LegalEntityStatus;
use App\Enums\PersonType;
use App\Models\LegalEntityVerification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class LegalEntityService
{
    /**
     * Submit (or resubmit) a legal-entity verification request. Only for a
     * self-declared legal entity; agents/sellers are verified by their role and
     * clients/designers must first declare `legal_entity` as their person type.
     *
     * @param  array{inn: string, company_name?: string|null, registration_certificate_file_id?: int|null}  $data
     */
    public function submit(User $user, array $data): LegalEntityVerification
    {
        if ($user->hasRoleBoundLegalStatus()) {
            throw ValidationException::withMessages([
                'person_type' => ['Your legal-entity status is already verified through your role.'],
            ]);
        }

        if ($user->person_type !== PersonType::LegalEntity) {
            throw ValidationException::withMessages([
                'person_type' => ['Declare yourself a legal entity before requesting verification.'],
            ]);
        }

        /** @var LegalEntityVerification $verification */
        $verification = $user->legalEntityVerification()->updateOrCreate([], [
            'inn' => $data['inn'],
            'company_name' => $data['company_name'] ?? null,
            'registration_certificate_file_id' => $data['registration_certificate_file_id'] ?? null,
            // A fresh submission (or resubmission after rejection) awaits moderation.
            'status' => LegalEntityStatus::Pending,
            'rejection_reason' => null,
            'verified_at' => null,
        ]);

        return $verification;
    }

    /**
     * Admin moderation: approve (mark verified) or reject (with a reason).
     */
    public function moderate(LegalEntityVerification $verification, LegalEntityStatus $status, ?string $reason = null): LegalEntityVerification
    {
        $verification->status = $status;
        $verification->rejection_reason = $status === LegalEntityStatus::Rejected ? $reason : null;
        $verification->verified_at = $status === LegalEntityStatus::Approved ? now() : null;
        $verification->save();

        return $verification;
    }
}
