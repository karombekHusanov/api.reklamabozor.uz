<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeIdentityRequest extends FormRequest
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
            // One-time auth_code returned by the MyID iframe/redirect.
            'auth_code' => ['required', 'string', 'max:2048'],
        ];
    }
}
