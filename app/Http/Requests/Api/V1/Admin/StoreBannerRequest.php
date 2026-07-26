<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerType;
use App\Rules\BannerLinkUrl;
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
        $type = $this->input('type');
        $isLink = $type === BannerType::Link->value;

        // Entity-backed banners (agent/product) require a target id; agent
        // targets must point at a real profile. Link banners carry no target —
        // they navigate via link_url instead.
        if ($isLink) {
            $targetRules = ['nullable', 'integer', 'min:1'];
        } else {
            $targetRules = ['required', 'integer', 'min:1'];

            if ($type === BannerType::Agent->value) {
                $targetRules[] = Rule::exists('agent_profiles', 'id');
            }
        }

        return [
            'title' => ['nullable', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'type' => ['required', Rule::enum(BannerType::class)],
            'target_id' => $targetRules,
            // Admin-managed artwork — any uploaded file may be referenced.
            'image_file_id' => ['required', 'integer', Rule::exists('files', 'id')],
            // Required for link banners; optional override otherwise. Accepts an
            // internal deep-link ("/agents/1") or an external URL ("https://…").
            'link_url' => [$isLink ? 'required' : 'nullable', 'string', 'max:500', BannerLinkUrl::rule()],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
