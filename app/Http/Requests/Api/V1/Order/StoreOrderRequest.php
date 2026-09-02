<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderDeadline;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B2C order — pick a category, name the project, describe the need, attach files.
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
            // Project name from the quick-order wizard ("Loyiha nomi"). Optional:
            // the simplified MVP request form omits it and the service falls back
            // to the category label.
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
            // Free-text hashtags (normalized server-side to a shared catalog).
            'hashtags' => ['sometimes', 'array', 'max:'.Order::MAX_HASHTAGS],
            'hashtags.*' => ['string', 'max:40'],
            // Client location — required so providers know where the work is.
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'location_label' => ['nullable', 'string', 'max:200'],
            // Optional admin region (null = all Uzbekistan). District must be a child of region.
            'region_id' => [
                'nullable',
                'required_with:district_id',
                'integer',
                Rule::exists('regions', 'id')
                    ->whereNull('parent_id')
                    ->where('is_active', true),
            ],
            'district_id' => [
                'nullable',
                'integer',
                Rule::exists('regions', 'id')
                    ->whereNotNull('parent_id')
                    ->where('is_active', true)
                    ->where(
                        fn ($query) => $this->input('region_id')
                            ? $query->where('parent_id', $this->input('region_id'))
                            : $query->whereRaw('0 = 1'),
                    ),
            ],
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
            // Files the client uploaded for this order. Optional: the simplified
            // MVP request form allows a text-only request (images are optional).
            'attachment_file_ids' => [
                'nullable',
                'array',
                'max:'.Order::MAX_ATTACHMENTS,
            ],
            'attachment_file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')->where('uploaded_by', $this->user()->id),
            ],
            // Whether attachment files appear on the public showcase detail page.
            'show_files_in_showcase' => ['sometimes', 'boolean'],
        ];
    }
}
