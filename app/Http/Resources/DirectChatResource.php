<?php

namespace App\Http\Resources;

use App\Models\DirectChat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Client ↔ agency direct conversation as seen by one participant.
 *
 * @mixin DirectChat
 */
class DirectChatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $other = $this->otherParticipant($user);

        // The agency identity belongs to the chat's linked profile, shown only
        // when the other side is the agent (the client has no provider profile).
        $agentProfile = $other->id === $this->agent_id ? $this->agentProfile : null;

        return [
            'id' => $this->id,
            'type' => 'direct',
            'order_id' => null,
            'order' => null,
            'other_participant' => [
                'id' => $other->id,
                'name' => trim($other->first_name.' '.($other->last_name ?? '')),
                'company_name' => $agentProfile?->company_name,
                'agent_profile_id' => $agentProfile?->id,
            ],
            'last_message' => $this->whenLoaded('lastMessage', fn () => $this->lastMessage
                ? new DirectChatMessageResource($this->lastMessage)
                : null),
            'unread_count' => $this->unreadCountFor($user),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
