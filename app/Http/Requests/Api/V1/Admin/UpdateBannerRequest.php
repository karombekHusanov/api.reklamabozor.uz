<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerType;
use App\Rules\BannerLinkUrl;
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
        $type = $this->input('type');

        // Link banners carry no target; agent/product still require one when set.
        if ($type === BannerType::Link->value) {
            $targetRules = ['nullable', 'integer', 'min:1'];
        } else {
            $targetRules = ['sometimes', 'required', 'integer', 'min:1'];

            if ($type === BannerType::Agent->value) {
                $targetRules[] = Rule::exists('agent_profiles', 'id');
            }
        }

        return [
            'title' => ['nullable', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'type' => ['sometimes', 'required', Rule::enum(BannerType::class)],
            'target_id' => $targetRules,
            'image_file_id' => ['sometimes', 'required', 'integer', Rule::exists('files', 'id')],
            // Required when switching a banner to the link type; accepts an
            // internal deep-link or an external http(s) URL.
            'link_url' => ['required_if:type,'.BannerType::Link->value, 'nullable', 'string', 'max:500', BannerLinkUrl::rule()],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
