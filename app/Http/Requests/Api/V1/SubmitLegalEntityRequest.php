<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitLegalEntityRequest extends FormRequest
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
            'inn' => ['required', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'registration_certificate_file_id' => [
                'nullable',
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
        ];
    }
}
