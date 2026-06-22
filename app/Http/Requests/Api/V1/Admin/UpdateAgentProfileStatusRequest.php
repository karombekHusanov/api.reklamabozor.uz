<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\AgentProfileStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentProfileStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(AgentProfileStatus::class)],
            'rejection_reason' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(fn () => $this->input('status') === AgentProfileStatus::Rejected->value),
            ],
        ];
    }
}
