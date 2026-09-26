<?php

namespace App\Http\Requests\Api\V1\Agent;

use App\Services\Pass\CardSource;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Card step of the in-app Propusk payment: either a saved card (`card_id`) or
 * a typed card (`card_number` + `expiry`, optionally `save_card`). Spaces /
 * dashes are stripped; the expiry comes as the printed "MM/YY" and is handed
 * to the provider as YYmm. Values are never echoed back in validation errors.
 */
class StartCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['card_number', 'expiry'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => preg_replace('/\D+/', '', (string) $this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'card_id' => ['nullable', 'integer', 'prohibits:card_number,expiry,save_card'],
            'card_number' => ['required_without:card_id', 'digits_between:16,19'],
            'save_card' => ['sometimes', 'boolean'],
            'expiry' => ['required_without:card_id', 'digits:4', function (string $attribute, mixed $value, Closure $fail): void {
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

    public function savedCardId(): ?int
    {
        $id = $this->validated('card_id');

        return $id !== null ? (int) $id : null;
    }

    /** A typed card; only valid when {@see savedCardId()} is null. */
    public function typedCard(): CardSource
    {
        return CardSource::card(
            (string) $this->validated('card_number'),
            $this->expiryYymm(),
            (bool) $this->validated('save_card', false),
        );
    }

    /** Provider format: "MMYY" as typed → "YYMM". */
    public function expiryYymm(): string
    {
        $mmyy = (string) $this->validated('expiry');

        return substr($mmyy, 2, 2).substr($mmyy, 0, 2);
    }
}
