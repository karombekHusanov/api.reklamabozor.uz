<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBannerRequest extends FormRequest
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
        // Agent banners must point at a real agent profile; product targets are
        // validated loosely until the products feature exists.
        $targetRules = ['required', 'integer', 'min:1'];

        if ($this->input('type') === BannerType::Agent->value) {
            $targetRules[] = Rule::exists('agent_profiles', 'id');
        }

        return [
            'title' => ['nullable', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'type' => ['required', Rule::enum(BannerType::class)],
            'target_id' => $targetRules,
            // Admin-managed artwork — any uploaded file may be referenced.
            'image_file_id' => ['required', 'integer', Rule::exists('files', 'id')],
            'link_url' => ['nullable', 'string', 'max:500', 'url'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
