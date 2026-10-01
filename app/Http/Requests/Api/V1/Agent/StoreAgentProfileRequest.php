<?php

namespace App\Http\Requests\Api\V1\Agent;

use App\Enums\LegalForm;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Phase 1 — verification application. Captures only the legal-entity / KYC
 * data the admin needs to approve the agent. Client-facing presentation
 * fields (logo, location, bio, categories) are filled later via
 * UpdateAgentProfileDetailsRequest once the profile is approved.
 */
class StoreAgentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Designers are individuals — they open a profile via the no-KYC
        // designer flow, never through the agency verification form.
        return $this->user()?->role !== Role::Designer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:200'],
            'legal_form' => ['required', Rule::in(LegalForm::values())],
            // Uzbekistan INN/STIR — 9 digits.
            'inn' => ['required', 'string', 'regex:/^\d{9}$/'],
            // Legal address (YaTT: registration address) — printed on contracts and acts.
            'legal_address' => ['required', 'string', 'max:300'],
            // The signer: the entrepreneur themself (YaTT) or the company manager.
            'director_name' => ['required', 'string', 'max:200'],
            'director_pinfl' => ['required', 'string', 'regex:/^\d{14}$/'],
            // Company-only (MChJ / AJ): the manager's position.
            'director_position' => [Rule::requiredIf($this->isCompany()), 'nullable', 'string', 'max:100'],
            // Passport series + number, e.g. AA1234567.
            'director_passport' => ['required', 'string', 'regex:/^[A-Za-z]{2}\d{7}$/'],
            // KYC scans — must reference files the user uploaded themselves.
            'director_passport_file_id' => ['required', 'integer', $this->ownedFile()],
            'registration_certificate_file_id' => ['required', 'integer', $this->ownedFile()],
            // Bank requisites.
            'bank_name' => ['required', 'string', 'max:200'],
            // Hisob raqami — 20–26 digits.
            'bank_account' => ['required', 'string', 'regex:/^\d{20,26}$/'],
            // Bank MFO code — 5 digits.
            'mfo' => ['required', 'string', 'regex:/^\d{5}$/'],
            'phone' => ['required', 'string', 'max:20'],
            // Click-wrap: the agency partnership offer must be accepted.
            'accept_offer' => ['required', 'accepted'],
        ];
    }

    /**
     * A YaTT is the entrepreneur themself: drop the company-only position
     * if it came along so it can't be stored stale.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isCompany()) {
            $this->merge(['director_position' => null]);
        }
    }

    private function isCompany(): bool
    {
        return LegalForm::tryFrom((string) $this->input('legal_form'))?->isCompany() ?? false;
    }

    /**
     * Rule enforcing the file belongs to the requesting user.
     */
    protected function ownedFile(): Exists
    {
        return Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id);
    }
}
