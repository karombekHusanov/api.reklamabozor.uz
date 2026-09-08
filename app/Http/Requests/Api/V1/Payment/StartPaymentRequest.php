<?php

namespace App\Http\Requests\Api\V1\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Client starts (or restarts) a Multicard payment: an in-app checkout, or a
 * shareable invoice link they pay later (QR / SMS).
 */
class StartPaymentRequest extends FormRequest
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
            'mode' => ['sometimes', 'in:checkout,invoice'],
            // Only meaningful with mode=invoice: Multicard texts the link to
            // the client's phone.
            'send_sms' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['mode' => $this->input('mode', 'checkout')]);
    }
}
