<?php

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A party accepts the addendum. `document_hash` (from the preview/detail) guards
 * against accepting a text that changed in the meantime.
 */
class ApproveAmendmentRequest extends FormRequest
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
            'accept_contract' => ['required', 'accepted'],
            'document_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_contract.required' => 'Confirm the additional agreement before approving it.',
            'accept_contract.accepted' => 'Confirm the additional agreement before approving it.',
        ];
    }
}
