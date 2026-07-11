<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderDeadline;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B2C order — pick a category, describe the need, attach reference files.
 * The title is derived from the category server-side.
 */
class StoreOrderRequest extends FormRequest
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
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            'description' => ['required', 'string', 'max:2000'],
            // Optional: direct the order to a single agency (chosen from its public
            // profile). Must be an approved provider; the service also checks it
            // serves the chosen category. Absent = normal broadcast order.
            'agent_profile_id' => [
                'nullable',
                'integer',
                Rule::exists('agent_profiles', 'id')->where('status', AgentProfileStatus::Approved->value),
            ],
            // How soon the work is needed (optional urgency preset).
            'deadline' => ['nullable', Rule::enum(OrderDeadline::class)],
            // One or more files the client uploaded for this order.
            'attachment_file_ids' => [
                'required',
                'array',
                'min:1',
                'max:'.Order::MAX_ATTACHMENTS,
            ],
            'attachment_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
        ];
    }
}
