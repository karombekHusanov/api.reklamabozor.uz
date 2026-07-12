<?php

namespace App\Http\Requests\Api\V1\Designer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDesignerProfileRequest extends FormRequest
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
            // Optional studio/brand name — a nickname, not a legal entity.
            'company_name' => ['nullable', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:2000'],
            // At least one design direction so the profile is listable from day one.
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')
                    ->where('is_active', true)
                    ->where('type', 'designer'),
            ],
        ];
    }
}
