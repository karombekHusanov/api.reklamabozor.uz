<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\OrderDeadline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B2C order — deliberately minimal: pick a category, attach a brief (TZ),
 * leave a comment. The title is derived from the category server-side.
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
            // How soon the work is needed (optional urgency preset).
            'deadline' => ['nullable', Rule::enum(OrderDeadline::class)],
            // The technical brief must reference a file the user uploaded themselves.
            'tz_file_id' => [
                'required',
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            // Extra reference files (slots 2-4). Each must be owned by the user.
            'attachment_file_ids' => ['nullable', 'array', 'max:3'],
            'attachment_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
        ];
    }
}
