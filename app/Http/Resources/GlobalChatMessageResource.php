<?php

namespace App\Http\Resources;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\GlobalChatMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin GlobalChatMessage */
class GlobalChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $profile = $this->displayProfile($user);

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

    /**
     * Prefer the profile matching the sender's active role, else any profile
     * that has a logo (covers agent↔designer switches without a "flip" bug).
     */
    private function displayProfile(User $user): ?AgentProfile
    {
        /** @var Collection<int, AgentProfile> $profiles */
        $profiles = $user->relationLoaded('providerProfiles')
            ? $user->providerProfiles
            : collect(array_filter([$user->agentProfile]));

        if ($profiles->isEmpty()) {
            return null;
        }

        $byRole = $profiles->first(
            fn (AgentProfile $profile) => $profile->provider_type->value === $user->role->value,
        );

        if ($byRole !== null) {
            return $byRole;
        }

        return $profiles->first(
            fn (AgentProfile $profile) => $profile->company_logo_file_id !== null,
        ) ?? $profiles->first();
    }

    private function senderAvatarUrl(User $user, ?AgentProfile $profile): ?string
    {
        $logo = $profile?->companyLogoFile?->url();
        if (is_string($logo) && $logo !== '') {
            return $logo;
        }

        // Fall back to any other provider logo, then the personal avatar.
        if ($user->relationLoaded('providerProfiles')) {
            foreach ($user->providerProfiles as $other) {
                if ($profile !== null && $other->is($profile)) {
                    continue;
                }
                $url = $other->companyLogoFile?->url();
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        $avatar = $user->avatarFile?->url();

        return is_string($avatar) && $avatar !== '' ? $avatar : null;
    }
}
