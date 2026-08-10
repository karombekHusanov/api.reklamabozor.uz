<?php

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Agent response to an order — either a priced offer or an empty-body interest (otklik).
 */
class StoreOfferRequest extends FormRequest
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
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
