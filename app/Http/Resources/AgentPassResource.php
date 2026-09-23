<?php

namespace App\Http\Resources;

use App\Models\AgentPass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentPass */
class AgentPassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'username' => $this->user->username,
            ]),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'price_tiyin' => $this->price_tiyin,
            'price_som' => intdiv($this->price_tiyin, 100),
            'source' => $this->source,
            'status' => $this->status,
            'is_current' => $this->starts_at <= now() && $this->expires_at > now(),
            'granted_by' => $this->granted_by,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
