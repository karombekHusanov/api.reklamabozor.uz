<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'telegram_id' => $this->telegram_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_file_id' => $this->avatar_file_id,
            'avatar' => $this->avatarFile?->url(),
            // Active role + every role the user holds (multirole).
            'role' => $this->role->value,
            'roles' => $this->allRoles()->map(fn ($role) => $role->value)->all(),
            'role_selected_at' => $this->role_selected_at,
            // KYC application status; null = agent-role user who never applied.
            'agent_profile_status' => $this->whenLoaded(
                'agentProfile',
                fn () => $this->agentProfile?->status->value,
                null,
            ),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
