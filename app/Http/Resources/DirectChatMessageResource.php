<?php

namespace App\Http\Resources;

use App\Models\DirectChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DirectChatMessage */
class DirectChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'body' => $this->body,
            'attachments' => FileResource::collection($this->attachments),
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
