<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetRoleRequest extends FormRequest
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
            // Self-selectable roles only — admin is granted out-of-band, never chosen.
            'role' => [
                'required',
                Rule::enum(Role::class)->except(Role::Admin),
            ],
        ];
    }
}
