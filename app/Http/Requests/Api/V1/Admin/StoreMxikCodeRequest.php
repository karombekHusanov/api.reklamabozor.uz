<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Operator adds a classifier entry. Codes come from the official MXIK list —
 * the form only checks shape, never invents a code.
 */
class StoreMxikCodeRequest extends FormRequest
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
        $id = $this->route('mxik_code')?->id;

        return [
            'code' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'regex:/^\d{6,20}$/',
                Rule::unique('mxik_codes', 'code')->ignore($id),
            ],
            'package_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'name_uz' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'name_ru' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // Categories whose pricelist rows default to this code.
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The MXIK code is a 6–20 digit number from the official classifier.',
        ];
    }
}
