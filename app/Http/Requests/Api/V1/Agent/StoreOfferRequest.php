<?php

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An agent's offer (proposal) for an order — a price plus a pitch comment.
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
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }
}
