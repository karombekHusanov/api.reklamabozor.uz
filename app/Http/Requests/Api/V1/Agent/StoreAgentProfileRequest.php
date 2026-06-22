<?php

namespace App\Http\Requests\Api\V1\Agent;

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
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:200'],
            // Legal-entity form, free text (e.g. YaTT, MChJ, AJ).
            'legal_form' => ['required', 'string', 'max:100'],
            // Uzbekistan INN/STIR — 9 digits.
            'inn' => ['required', 'string', 'regex:/^\d{9}$/'],
            'director_name' => ['required', 'string', 'max:200'],
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
        ];
    }

    /**
     * Rule enforcing the file belongs to the requesting user.
     */
    protected function ownedFile(): Exists
    {
        return Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id);
    }
}
