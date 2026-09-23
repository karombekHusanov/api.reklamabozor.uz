<?php

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;

/** SMS code step of the in-app Propusk payment. */
class ConfirmCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['otp' => preg_replace('/\D+/', '', (string) $this->input('otp'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'otp' => ['required', 'digits_between:4,8'],
        ];
    }
}
