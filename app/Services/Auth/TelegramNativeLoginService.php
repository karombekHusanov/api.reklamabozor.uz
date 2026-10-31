<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Telegram login for the native mobile app, which has no Mini App initData.
 *
 * 1. The app calls start(): gets a public `code` (goes into the bot deep link
 *    t.me/<bot>?start=login_<code>) and a private `poll_secret` it keeps.
 * 2. In Telegram the user confirms (or shares their phone first); the webhook
 *    calls confirm() with the Telegram account behind the chat.
 * 3. The app polls with code + secret and receives a Sanctum token once.
 *
 * The code alone never yields a token — only the device holding the secret
 * can redeem a confirmed request. State lives in the cache for TTL seconds.
 */
class TelegramNativeLoginService
{
    public const TTL_SECONDS = 600;

    public const TOKEN_NAME = 'mobile-app';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    private const KEY = 'tg-native-login:';

    private const CONTACT_KEY = 'tg-native-login-contact:';

    /** Keep at most this many mobile tokens per user (oldest revoked). */
    private const MAX_TOKENS = 5;

    /**
     * @return array{code: string, poll_secret: string, bot_url: string, expires_in: int}
     */
    public function start(?string $ip = null): array
    {
        $code = Str::random(32);
        $secret = Str::random(48);

        Cache::put(self::KEY.$code, [
            'secret' => hash('sha256', $secret),
            'status' => self::STATUS_PENDING,
            'user_id' => null,
            'ip' => $ip,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ], self::TTL_SECONDS);

        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        return [
            'code' => $code,
            'poll_secret' => $secret,
            'bot_url' => "https://t.me/{$bot}?start=login_{$code}",
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    public function isPending(string $code): bool
    {
        return ($this->entry($code)['status'] ?? null) === self::STATUS_PENDING;
    }

    /** Bind a pending request to the Telegram account that confirmed it. */
    public function confirm(string $code, User $user): bool
    {
        return $this->transition($code, function (array $entry) use ($user): ?array {
            if ($entry['status'] !== self::STATUS_PENDING || ! $user->is_active) {
                return null;
            }

            return [...$entry, 'status' => self::STATUS_CONFIRMED, 'user_id' => $user->id];
        });
    }

    public function cancel(string $code): bool
    {
        return $this->transition($code, fn (array $entry): ?array => $entry['status'] === self::STATUS_PENDING
            ? [...$entry, 'status' => self::STATUS_CANCELLED]
            : null);
    }

    /**
     * Phone-less Telegram user opened a login link: remember which request to
     * confirm once they share their contact.
     */
    public function awaitContact(int $telegramId, string $code): void
    {
        Cache::put(self::CONTACT_KEY.$telegramId, $code, self::TTL_SECONDS);
    }

    public function pullAwaitingContact(int $telegramId): ?string
    {
        $code = Cache::pull(self::CONTACT_KEY.$telegramId);

        return is_string($code) ? $code : null;
    }

    /**
     * Redeem a request. A confirmed one yields a token exactly once; unknown
     * code or wrong secret look the same as expired (nothing to probe).
     *
     * @return array{status: string, user?: User, token?: string}
     */
    public function poll(string $code, string $secret): array
    {
        $entry = $this->entry($code);

        if ($entry === null || ! hash_equals($entry['secret'], hash('sha256', $secret))) {
            return ['status' => self::STATUS_EXPIRED];
        }

        if ($entry['status'] === self::STATUS_PENDING) {
            return ['status' => self::STATUS_PENDING];
        }

        if ($entry['status'] === self::STATUS_CANCELLED) {
            Cache::forget(self::KEY.$code);

            return ['status' => self::STATUS_CANCELLED];
        }

        // Confirmed: consume under a lock so two polls can't both mint a token.
        return Cache::lock(self::KEY.$code.':redeem', 5)->block(3, function () use ($code): array {
            $entry = $this->entry($code);
            if ($entry === null || $entry['status'] !== self::STATUS_CONFIRMED) {
                return ['status' => self::STATUS_EXPIRED];
            }
            Cache::forget(self::KEY.$code);

            $user = User::query()->find($entry['user_id']);
            if ($user === null || ! $user->is_active) {
                return ['status' => self::STATUS_EXPIRED];
            }

            $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;
            $this->pruneTokens($user);

            return ['status' => self::STATUS_CONFIRMED, 'user' => $user, 'token' => $token];
        });
    }

    /**
     * @return array{secret: string, status: string, user_id: int|null, ip: string|null, expires_at: int}|null
     */
    private function entry(string $code): ?array
    {
        $entry = Cache::get(self::KEY.$code);

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param  callable(array): ?array  $change  returns the new entry, or null to refuse
     */
    private function transition(string $code, callable $change): bool
    {
        return Cache::lock(self::KEY.$code.':state', 5)->block(3, function () use ($code, $change): bool {
            $entry = $this->entry($code);
            if ($entry === null) {
                return false;
            }
            $next = $change($entry);
            if ($next === null) {
                return false;
            }
            // Keep the original expiry window rather than extending it.
            $left = (int) ($entry['expires_at'] ?? 0) - now()->timestamp;
            if ($left <= 0) {
                return false;
            }
            Cache::put(self::KEY.$code, $next, $left);

            return true;
        });
    }

    private function pruneTokens(User $user): void
    {
        $keep = $user->tokens()->where('name', self::TOKEN_NAME)->orderByDesc('id')->limit(self::MAX_TOKENS)->pluck('id');
        $user->tokens()->where('name', self::TOKEN_NAME)->whereNotIn('id', $keep)->delete();
    }
}
