<?php

namespace App\Http\Resources;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as seen by one of its participants: the order thumbnail,
 * who the other side is, the last message, and the unread counter.
 *
 * @mixin Chat
 */
class ChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $other = $this->otherParticipant($user);

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order' => [
                'id' => $this->order?->id,
                'title' => $this->order?->title,
                'status' => $this->order?->status->value,
                'category' => $this->order?->relationLoaded('category') && $this->order->category
                    ? new CategoryResource($this->order->category)
                    : null,
            ],
            'other_participant' => [
                'id' => $other->id,
                'name' => trim($other->first_name.' '.($other->last_name ?? '')),
                'company_name' => $other->agentProfile?->company_name,
                'agent_profile_id' => $other->agentProfile?->id,
            ],
            'last_message' => $this->whenLoaded('lastMessage', fn () => $this->lastMessage
                ? new ChatMessageResource($this->lastMessage)
                : null),
            'unread_count' => $this->unreadCountFor($user),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
