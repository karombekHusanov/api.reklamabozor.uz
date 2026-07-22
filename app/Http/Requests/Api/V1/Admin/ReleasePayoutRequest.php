<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReleasePayoutRequest extends FormRequest
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
            // Optional override of the computed amount, in tiyin.
            'amount' => ['nullable', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            // v1 supports manual releases; `multicard` (automated) lands later.
            'method' => ['nullable', Rule::in(['manual'])],
        ];
    }
}
