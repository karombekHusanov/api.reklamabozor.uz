<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBannerRequest extends FormRequest
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
        $targetRules = ['sometimes', 'required', 'integer', 'min:1'];

        if ($this->input('type') === BannerType::Agent->value) {
            $targetRules[] = Rule::exists('agent_profiles', 'id');
        }

        return [
            'title' => ['nullable', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'type' => ['sometimes', 'required', Rule::enum(BannerType::class)],
            'target_id' => $targetRules,
            'image_file_id' => ['sometimes', 'required', 'integer', Rule::exists('files', 'id')],
            'link_url' => ['nullable', 'string', 'max:500', 'url'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
