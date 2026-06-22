<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
            'name_uz' => ['sometimes', 'required', 'string', 'max:100'],
            'name_ru' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['sometimes', Rule::enum(CategoryType::class)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
