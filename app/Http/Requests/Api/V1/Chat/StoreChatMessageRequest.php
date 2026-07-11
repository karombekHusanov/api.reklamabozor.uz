<?php

namespace App\Http\Requests\Api\V1\Chat;

use App\Services\Chat\MessageAttachments;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
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
            'body' => ['nullable', 'string', 'max:2000', 'required_without:file_ids'],
            'file_ids' => ['nullable', 'array', 'max:'.MessageAttachments::MAX_PER_MESSAGE, 'required_without:body'],
            'file_ids.*' => ['integer', 'exists:files,id'],
        ];
    }
}
