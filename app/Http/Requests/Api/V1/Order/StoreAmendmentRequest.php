<?php

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Client or agent proposes an amendment (Qo'shimcha kelishuv): a new pricelist
 * and delivery deadline that replaces the current terms once approved.
 */
class StoreAmendmentRequest extends FormRequest
{
    /** Hard cap on line items in one amendment. */
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
            'deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
