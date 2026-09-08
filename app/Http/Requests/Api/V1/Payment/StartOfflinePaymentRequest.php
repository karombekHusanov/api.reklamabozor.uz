<?php

namespace App\Http\Requests\Api\V1\Payment;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Client asks for an invoice they will pay outside the gateway — cash at the
 * platform's desk, or a bank transfer from a company account.
 */
class StartOfflinePaymentRequest extends FormRequest
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
            'method' => [
                'required',
                Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::offline())),
            ],
        ];
    }
}
