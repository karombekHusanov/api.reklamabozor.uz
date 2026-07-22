<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PersonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetPersonTypeRequest extends FormRequest
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
            'person_type' => ['required', Rule::enum(PersonType::class)],
        ];
    }
}
