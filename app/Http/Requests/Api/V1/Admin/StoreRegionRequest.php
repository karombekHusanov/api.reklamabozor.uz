<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegionRequest extends FormRequest
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
            'name_uz' => ['required', 'string', 'max:100'],
            'name_ru' => ['required', 'string', 'max:100'],
            // Null = top-level region; set = district under a root.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('regions', 'id')->whereNull('parent_id'),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
