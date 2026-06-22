<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
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
            // Admin-controllable targets only (validity of the transition is checked in the service).
            'status' => [
                'required',
                Rule::in([
                    OrderStatus::InProgress->value,
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                ]),
            ],
        ];
    }
}
