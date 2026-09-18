<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manager records a manual refund for a problem order. The amount is a human
 * judgement call (rarely the full deal price), settled outside the platform —
 * this is the audit entry, not a ledger transaction.
 */
class RefundOrderProblemRequest extends FormRequest
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
            // Tiyin.
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:cash,bank_transfer,card'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
