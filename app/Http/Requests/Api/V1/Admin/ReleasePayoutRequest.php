<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReleasePayoutRequest extends FormRequest
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
            // Optional override of the computed amount, in tiyin.
            'amount' => ['nullable', 'integer', 'min:0'],
            // The payment-order number — required for a bank transfer, which
            // has no other trace inside the platform.
            'reference' => [
                Rule::requiredIf(fn (): bool => $this->releaseMethod() === 'bank'),
                'string',
                'max:255',
            ],
            // Money reaches the agent by bank transfer; `manual` stays accepted
            // for anything settled outside the banking channel (e.g. cash desk).
            'method' => ['nullable', Rule::in(['bank', 'manual'])],
        ];
    }

    /** The channel this release is recorded on (defaults to the configured one). */
    private function releaseMethod(): string
    {
        return (string) ($this->input('method') ?? config('payouts.channel', 'bank'));
    }
}
