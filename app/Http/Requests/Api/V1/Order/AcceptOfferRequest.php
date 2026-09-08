<?php

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Client accepts a priced offer. Accepting the offer *is* accepting the
 * three-party service contract shown in the drawer, so the consent flag is
 * mandatory; `contract_hash` (returned by the preview) guards against accepting
 * a document the agent has since revised.
 */
class AcceptOfferRequest extends FormRequest
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
            'contract_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accept_contract.required' => 'Confirm the contract before accepting the offer.',
            'accept_contract.accepted' => 'Confirm the contract before accepting the offer.',
        ];
    }
}
