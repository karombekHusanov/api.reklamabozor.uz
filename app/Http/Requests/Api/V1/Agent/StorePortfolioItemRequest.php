<?php

namespace App\Http\Requests\Api\V1\Agent;

use App\Models\AgentPortfolioItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioItemRequest extends FormRequest
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
            // Backward-compatible single cover image.
            'image_file_id' => [
                'required_without:image_file_ids',
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'image_file_ids' => [
                'required_without:image_file_id',
                'array',
                'min:1',
                'max:'.AgentPortfolioItem::MAX_IMAGES,
            ],
            'image_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'attachment_file_ids' => [
                'nullable',
                'array',
                'max:'.AgentPortfolioItem::MAX_ATTACHMENTS,
            ],
            'attachment_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'link_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
