<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GrantPassRequest extends FormRequest
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
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
