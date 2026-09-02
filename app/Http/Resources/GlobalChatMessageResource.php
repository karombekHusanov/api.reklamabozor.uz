<?php

namespace App\Http\Resources;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\GlobalChatMessage;
use App\Models\User;
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
        $user = $this->user;
        // 1 user = 1 profile: the sender's single provider profile (null for a
        // pure client). Not keyed off the active role — the profile's own kind
        // stands on its own, so an agent↔designer role switch never flips it.
        $profile = $user->profile;

        return [
            'id' => $this->id,
            'body' => $this->body,
            'attachments' => FileResource::collection($this->attachments),
            'created_at' => $this->created_at?->toIso8601String(),
            'sender' => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.($user->last_name ?? '')),
                'username' => $user->username,
                'role' => $user->role->value,
                // Approved agencies speak under their company name.
                'company_name' => $profile?->company_name,
                // Same priority as marketplace cards: studio logo, else personal photo.
                'avatar_url' => $this->senderAvatarUrl($user, $profile),
                // Tap target: only approved agencies have a public in-app profile page.
                'agent_profile_id' => $profile?->status === AgentProfileStatus::Approved
                    ? $profile->id
                    : null,
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

    private function senderAvatarUrl(User $user, ?AgentProfile $profile): ?string
    {
        // Same priority as marketplace cards: studio logo, else personal photo.
        $logo = $profile?->companyLogoFile?->url();
        if (is_string($logo) && $logo !== '') {
            return $logo;
        }

        $avatar = $user->avatarFile?->url();

        return is_string($avatar) && $avatar !== '' ? $avatar : null;
    }
}
