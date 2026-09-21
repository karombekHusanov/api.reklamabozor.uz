<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GrantTenderAccessRequest extends FormRequest
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
            // Manager interview / business-check note — mandatory for the audit.
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
