<?php

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Agent sends (or replaces) the pricelist on their offer — the priced "contract"
 * step after negotiating in the order chat. At least one line is required.
 */
class SetOfferPricelistRequest extends FormRequest
{
    /** Hard cap on line items in a single pricelist. */
    public const MAX_ITEMS = 50;

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
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
