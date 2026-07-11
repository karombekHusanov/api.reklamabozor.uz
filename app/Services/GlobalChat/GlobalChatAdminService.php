<?php

namespace App\Services\GlobalChat;

use App\Enums\Role;
use App\Models\GlobalChatBan;
use App\Models\GlobalChatMessage;
use App\Models\GlobalChatRule;
use App\Models\GlobalChatSetting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Moderation surface for the admin panel: message feed with soft deletion,
 * bans, cooldown rules, and the global settings/pinned announcement.
 */
class GlobalChatAdminService
{
    /**
     * @param  array{search?: string|null, user_id?: int|null, include_deleted?: bool, per_page?: int}  $filters
     */
    public function messages(array $filters): LengthAwarePaginator
    {
        return GlobalChatMessage::query()
            ->with(['user.agentProfile', 'deletedBy', 'attachments'])
            ->when(! ($filters['include_deleted'] ?? false), fn ($q) => $q->visible())
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['search'] ?? null, function ($q, $search): void {
                $likeTerm = '%'.mb_strtolower($search).'%';
                $q->where(function ($builder) use ($likeTerm): void {
                    $builder
                        ->whereRaw('LOWER(body) LIKE ?', [$likeTerm])
                        ->orWhereHas('user', function ($userQuery) use ($likeTerm): void {
                            $userQuery
                                ->whereRaw('LOWER(first_name) LIKE ?', [$likeTerm])
                                ->orWhereRaw('LOWER(username) LIKE ?', [$likeTerm]);
                        });
                });
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 30);
    }

    public function deleteMessage(GlobalChatMessage $message, User $actor): GlobalChatMessage
    {
        if ($message->deleted_at === null) {
            $message->update([
                'deleted_at' => now(),
                'deleted_by' => $actor->id,
            ]);
        }

        return $message->load(['user.agentProfile', 'deletedBy']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = GlobalChatRule::query()->with('user')->get();

        return [
            'roles' => $rules->whereNull('user_id')->values(),
            'users' => $rules->whereNotNull('user_id')->values(),
        ];
    }

    /**
     * Replace the role-level cooldowns. 0 (or omission) removes the rule.
     *
     * @param  array<int, array{role: string, cooldown_seconds: int}>  $roleRules
     */
    public function setRoleRules(array $roleRules): void
    {
        foreach ($roleRules as $rule) {
            if ($rule['cooldown_seconds'] > 0) {
                GlobalChatRule::query()->updateOrCreate(
                    ['role' => $rule['role'], 'user_id' => null],
                    ['cooldown_seconds' => $rule['cooldown_seconds']],
                );
            } else {
                GlobalChatRule::query()->where('role', $rule['role'])->delete();
            }
        }
    }

    public function setUserRule(int $userId, int $cooldownSeconds): GlobalChatRule
    {
        return GlobalChatRule::query()->updateOrCreate(
            ['user_id' => $userId, 'role' => null],
            ['cooldown_seconds' => $cooldownSeconds],
        )->load('user');
    }

    public function removeUserRule(int $userId): void
    {
        GlobalChatRule::query()->where('user_id', $userId)->delete();
    }

    public function bans(): LengthAwarePaginator
    {
        return GlobalChatBan::query()
            ->with(['user', 'bannedBy'])
            ->active()
            ->latest('id')
            ->paginate(30);
    }

    public function ban(int $userId, User $actor, ?string $reason, ?int $durationHours): GlobalChatBan
    {
        $target = User::query()->findOrFail($userId);

        if ($target->role === Role::Admin) {
            throw ValidationException::withMessages([
                'user_id' => ['Administrators cannot be blocked.'],
            ]);
        }

        // One active ban per user: re-banning replaces the previous one.
        GlobalChatBan::query()->where('user_id', $userId)->delete();

        return GlobalChatBan::create([
            'user_id' => $userId,
            'banned_by' => $actor->id,
            'reason' => $reason,
            'expires_at' => $durationHours !== null ? now()->addHours($durationHours) : null,
        ])->load(['user', 'bannedBy']);
    }

    public function unban(GlobalChatBan $ban): void
    {
        $ban->delete();
    }

    /**
     * @param  array{enabled?: bool, max_message_length?: int, pinned_message?: string|null}  $data
     */
    public function updateSettings(array $data, User $actor): GlobalChatSetting
    {
        $settings = GlobalChatSetting::current();

        if (array_key_exists('pinned_message', $data)) {
            $pinned = $data['pinned_message'];
            $data['pinned_by'] = filled($pinned) ? $actor->id : null;
            $data['pinned_at'] = filled($pinned) ? now() : null;
            $data['pinned_message'] = filled($pinned) ? $pinned : null;
        }

        $settings->update($data);

        return $settings->refresh();
    }
}
