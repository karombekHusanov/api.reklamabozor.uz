<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Operator records money handed back for a price-lowering addendum. The gateway
 * has no partial refund, so this is a bookkeeping entry for a manual return.
 */
class SettleAmendmentRefundRequest extends FormRequest
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
            // Optional echo of the amount shown in the modal; must match in full.
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'method' => ['required', 'in:cash,bank_transfer,card'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
