<?php

namespace App\Http\Resources;

use App\Models\GlobalChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GlobalChatMessage */
class GlobalChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'attachments' => FileResource::collection($this->attachments),
            'created_at' => $this->created_at?->toIso8601String(),
            'sender' => [
                'id' => $this->user->id,
                'name' => trim($this->user->first_name.' '.($this->user->last_name ?? '')),
                'username' => $this->user->username,
                'role' => $this->user->role->value,
                // Approved agencies speak under their company name.
                'company_name' => $this->user->agentProfile?->company_name,
            ],
            // Moderation fields, present only on the admin surface.
            'deleted_at' => $this->when(
                $request->routeIs('admin.*') || $request->is('api/v1/admin/*'),
                fn () => $this->deleted_at?->toIso8601String(),
            ),
            'deleted_by' => $this->when(
                $request->is('api/v1/admin/*') && $this->relationLoaded('deletedBy'),
                fn () => $this->deletedBy?->first_name,
            ),
        ];
    }
}
