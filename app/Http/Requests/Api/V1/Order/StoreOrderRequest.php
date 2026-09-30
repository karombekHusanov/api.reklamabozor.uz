<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderDeadline;
use App\Enums\OrderRoute;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * B2C order. Only the description is required: category, region, files and the
 * map pin are optional, and a category-less order is broadcast to every
 * approved provider.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tender is manager-gated per account (OrderService::create re-checks).
        if ($this->input('route') === OrderRoute::Tender->value) {
            return (bool) $this->user()?->canCreateTender();
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            // Project name from the quick-order wizard ("Loyiha nomi"). Optional:
            // the simplified MVP request form omits it and the service falls back
            // to the category label.
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
            // Which rule set the request runs on (fixed at creation). Omitted =
            // tender for accounts with Tender access, tezkor for everyone else.
            'route' => ['nullable', Rule::enum(OrderRoute::class)],
            // Free-text hashtags (normalized server-side to a shared catalog).
            'hashtags' => ['sometimes', 'array', 'max:'.Order::MAX_HASHTAGS],
            'hashtags.*' => ['string', 'max:40'],
            // Optional map pin — both coordinates or neither.
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
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
            // Work window picked on the calendar (both dates or neither) and
            // the client's budget in so'm (stored as the upper bound).
            'deadline_from' => ['nullable', 'required_with:deadline_to', 'date_format:Y-m-d', 'after_or_equal:today'],
            'deadline_to' => ['nullable', 'required_with:deadline_from', 'date_format:Y-m-d', 'after_or_equal:deadline_from'],
            'budget' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
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
