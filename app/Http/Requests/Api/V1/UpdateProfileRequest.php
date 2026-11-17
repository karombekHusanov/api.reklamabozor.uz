<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
            // A real name: at least two letters (Telegram names like "." are replaced here).
            'first_name' => ['sometimes', 'required', 'string', 'max:100', 'regex:/\p{L}.*\p{L}/su'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // Avatar must reference a file the user uploaded themselves.
            'avatar_file_id' => [
                'nullable',
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.regex' => 'Your name needs at least 2 letters.',
        ];
    }
}
