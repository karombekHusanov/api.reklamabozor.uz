<?php

namespace App\Services\GlobalChat;

use App\Enums\Role;
use App\Models\GlobalChatBan;
use App\Models\GlobalChatMessage;
use App\Models\GlobalChatRule;
use App\Models\GlobalChatSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Community chat rules engine. Writing is governed by three layers:
 *   1. the global switch + message length (global_chat_settings),
 *   2. write bans (global_chat_bans),
 *   3. cooldowns (global_chat_rules: user override ?? role rule ?? none).
 * Admins bypass bans and cooldowns.
 */
class GlobalChatService
{
    /**
     * Everything the mini app needs to render the composer state.
     *
     * @return array<string, mixed>
     */
    public function meta(User $user): array
    {
        $settings = GlobalChatSetting::current();
        $ban = $this->activeBan($user);
        $cooldown = $this->cooldownFor($user);
        $nextAllowedAt = $this->nextAllowedAt($user, $cooldown);

        return [
            'enabled' => $settings->enabled,
            'max_message_length' => $settings->max_message_length,
            'pinned_message' => $settings->pinned_message,
            'pinned_at' => $settings->pinned_at?->toIso8601String(),
            'me' => [
                'banned' => $ban !== null,
                'ban_expires_at' => $ban?->expires_at?->toIso8601String(),
                'cooldown_seconds' => $cooldown,
                'next_allowed_at' => $nextAllowedAt?->toIso8601String(),
            ],
        ];
    }

    /**
     * Visible messages for the app. `after_id` powers polling, `before_id`
     * loads older history; both are cursor-style and cheap.
     *
     * @return Collection<int, GlobalChatMessage>
     */
    public function messages(?int $afterId = null, ?int $beforeId = null, int $limit = 50): Collection
    {
        $query = GlobalChatMessage::query()
            ->visible()
            ->with('user.agentProfile');

        if ($afterId !== null) {
            return $query->where('id', '>', $afterId)->oldest('id')->limit($limit)->get();
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        // Latest page, returned in chronological order for rendering.
        return $query->latest('id')->limit($limit)->get()->reverse()->values();
    }

    public function post(User $user, string $body): GlobalChatMessage
    {
        $settings = GlobalChatSetting::current();

        if (! $settings->enabled) {
            throw ValidationException::withMessages([
                'body' => ['The chat is temporarily disabled.'],
            ]);
        }

        if (mb_strlen($body) > $settings->max_message_length) {
            throw ValidationException::withMessages([
                'body' => ["Message is too long (max {$settings->max_message_length} characters)."],
            ]);
        }

        // The user-row lock serialises concurrent posts from the same account,
        // so two parallel requests cannot both slip through the cooldown check.
        return DB::transaction(function () use ($user, $body): GlobalChatMessage {
            if ($user->role !== Role::Admin) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $this->assertNotBanned($user);
                $this->assertCooldownPassed($user);
            }

            return GlobalChatMessage::create([
                'user_id' => $user->id,
                'body' => $body,
            ])->load('user.agentProfile');
        });
    }

    /** The user's effective cooldown in seconds (0 = unlimited). */
    public function cooldownFor(User $user): int
    {
        if ($user->role === Role::Admin) {
            return 0;
        }

        $rules = GlobalChatRule::query()
            ->where('user_id', $user->id)
            ->orWhere('role', $user->role->value)
            ->get();

        $userRule = $rules->firstWhere('user_id', $user->id);
        $roleRule = $rules->firstWhere('role', $user->role->value);

        return (int) ($userRule?->cooldown_seconds ?? $roleRule?->cooldown_seconds ?? 0);
    }

    public function activeBan(User $user): ?GlobalChatBan
    {
        return GlobalChatBan::query()
            ->where('user_id', $user->id)
            ->active()
            ->latest('id')
            ->first();
    }

    /**
     * When the user may write next. Deleted messages still count — moderation
     * must not reset someone's cooldown.
     */
    private function nextAllowedAt(User $user, int $cooldown): ?CarbonInterface
    {
        if ($cooldown === 0) {
            return null;
        }

        $last = GlobalChatMessage::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($last === null) {
            return null;
        }

        $next = $last->created_at->addSeconds($cooldown);

        return $next->isFuture() ? $next : null;
    }

    private function assertNotBanned(User $user): void
    {
        $ban = $this->activeBan($user);

        if ($ban !== null) {
            throw ValidationException::withMessages([
                'body' => [
                    $ban->expires_at === null
                        ? 'You are blocked from the chat.'
                        : "You are blocked from the chat until {$ban->expires_at->toDateTimeString()}.",
                ],
            ]);
        }
    }

    private function assertCooldownPassed(User $user): void
    {
        $cooldown = $this->cooldownFor($user);
        $next = $this->nextAllowedAt($user, $cooldown);

        if ($next !== null) {
            throw ValidationException::withMessages([
                'body' => ["You can write again at {$next->toDateTimeString()}."],
            ]);
        }
    }
}
