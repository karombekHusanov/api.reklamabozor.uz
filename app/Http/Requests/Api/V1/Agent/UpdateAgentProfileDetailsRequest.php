<?php

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 2 — client-facing presentation. Editable only once the profile is
 * approved (enforced in the controller). All fields are partial-update
 * ("sometimes") and feed the profile-completion percentage.
 */
class UpdateAgentProfileDetailsRequest extends FormRequest
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
            // Logo must reference a file the user uploaded themselves.
            'company_logo_file_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'location_label' => ['sometimes', 'nullable', 'string', 'max:200'],
            'results_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_ids' => ['sometimes', 'array'],
            // Providers may offer advertising (agent) and/or design (designer) services,
            // so any active category is allowed.
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
        ];
    }
}
