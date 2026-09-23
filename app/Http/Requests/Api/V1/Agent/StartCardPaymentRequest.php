<?php

namespace App\Http\Requests\Api\V1\Agent;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Card step of the in-app Propusk payment. Spaces/dashes are stripped; the
 * expiry comes as the printed "MM/YY" and is handed to the provider as YYmm.
 * Values are never echoed back in validation errors.
 */
class StartCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'card_number' => preg_replace('/\D+/', '', (string) $this->input('card_number')),
            'expiry' => preg_replace('/\D+/', '', (string) $this->input('expiry')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'card_number' => ['required', 'digits_between:16,19'],
            'expiry' => ['required', 'digits:4', function (string $attribute, mixed $value, Closure $fail): void {
                $month = (int) substr((string) $value, 0, 2);
                $year = 2000 + (int) substr((string) $value, 2, 2);

                if ($month < 1 || $month > 12) {
                    $fail('The card expiry month is invalid.');

                    return;
                }

                if ($year * 100 + $month < (int) now()->format('Ym')) {
                    $fail('The card has expired.');
                }
            }],
        ];
    }

    /** Provider format: "MMYY" as typed → "YYMM". */
    public function expiryYymm(): string
    {
        $mmyy = (string) $this->validated('expiry');

        return substr($mmyy, 2, 2).substr($mmyy, 0, 2);
    }
}
