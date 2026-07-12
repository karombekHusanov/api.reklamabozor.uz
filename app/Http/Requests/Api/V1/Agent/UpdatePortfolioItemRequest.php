<?php

namespace App\Http\Requests\Api\V1\Agent;

use App\Models\AgentPortfolioItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePortfolioItemRequest extends FormRequest
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
            'image_file_id' => [
                'sometimes',
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'image_file_ids' => [
                'sometimes',
                'array',
                'min:1',
                'max:'.AgentPortfolioItem::MAX_IMAGES,
            ],
            'image_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'attachment_file_ids' => [
                'sometimes',
                'nullable',
                'array',
                'max:'.AgentPortfolioItem::MAX_ATTACHMENTS,
            ],
            'attachment_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'title' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'link_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
