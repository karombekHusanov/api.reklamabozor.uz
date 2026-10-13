<?php

namespace App\Http\Requests\Api\V1\Individual;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Individual ("jismoniy") executor application — a light KYC: full name, a
 * passport scan and a selfie, plus the categories served. Admin-approved like
 * an agency, but with none of the legal-entity requisites (no INN, contract,
 * or bank account). The resulting profile is Tezkor-only.
 */
class StoreIndividualProfileRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:200'],
            // Optional alternate contact (phone/Telegram) beyond the account phone.
            'alt_contact' => ['nullable', 'string', 'max:100'],
            // KYC scans — must reference files the user uploaded themselves.
            'passport_file_id' => ['required', 'integer', $this->ownedFile()],
            'selfie_file_id' => ['required', 'integer', $this->ownedFile()],
            // At least one direction so the profile is listable from day one.
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * Rule enforcing the file belongs to the requesting user.
     */
    protected function ownedFile(): Exists
    {
        return Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id);
    }
}
