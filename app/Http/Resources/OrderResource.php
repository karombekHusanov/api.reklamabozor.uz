<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'attachment_file_ids' => $this->allAttachmentFileIds(),
            'attachment_files' => FileResource::collection(
                $this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [],
            ),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'status' => $this->status->value,
            // The single agency this order was directed to, or null for a normal
            // broadcast order (shown to every provider in the category).
            'target_agent' => $this->whenLoaded('targetAgent', fn () => $this->targetAgent ? [
                'id' => $this->targetAgent->id,
                'company_name' => $this->targetAgent->agentProfile?->company_name,
            ] : null),
            'work_submitted_at' => $this->work_submitted_at,
            'completed_at' => $this->completed_at,
            'auto_completed' => $this->auto_completed,
            'review' => new ReviewResource($this->whenLoaded('review')),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'offers_count' => $this->whenCounted('offers'),
            'views_count' => $this->whenCounted('views'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
