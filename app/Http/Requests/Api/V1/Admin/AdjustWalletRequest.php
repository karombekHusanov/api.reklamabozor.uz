<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdjustWalletRequest extends FormRequest
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
            // Signed, so'm: positive credits, negative debits (never below zero).
            'amount_som' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
