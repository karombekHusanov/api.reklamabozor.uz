<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TelegramLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * With verification enabled, identity is derived from the signed
     * `init_data` only; the plain fields exist for dev/test environments
     * (and are ignored whenever verification is on).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'init_data' => ['nullable', 'string', 'max:8192'],
            'telegram_id' => ['nullable', 'integer'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'],
        ];
    }
}
