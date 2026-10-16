<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Services\Agent\AgentAccountLinker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /** Failed admin logins per email+IP before the lockout kicks in. */
    public const ADMIN_LOGIN_MAX_ATTEMPTS = 5;

    /** How long a locked email+IP pair stays locked (seconds). */
    public const ADMIN_LOGIN_LOCKOUT_SECONDS = 15 * 60;

    /** Admin panel token lifetime (12 hours). */
    public const ADMIN_TOKEN_TTL_MINUTES = 12 * 60;

    /** Name of the tokens the mini app login issues. */
    public const MINI_APP_TOKEN_NAME = 'auth';

    /**
     * How many mini app tokens a user keeps after a login (the new one
     * included): one per device the person actually uses — phone, desktop
     * Telegram, a browser — without personal_access_tokens growing per launch.
     */
    public const MINI_APP_MAX_TOKENS = 3;

    public function __construct(
        private readonly AgentAccountLinker $agentLinker,
        private readonly TelegramInitDataValidator $initDataValidator,
    ) {}

    /**
     * Authenticate or register a Telegram user.
     *
     * With verification enabled (production), identity comes exclusively from
     * the HMAC-verified initData — any client-supplied fields are ignored.
     * Phone is never accepted here: it only enters via the bot's verified
     * contact flow, which is also what the agent account linker trusts.
     *
     * @param  array{init_data?: ?string, telegram_id?: int, first_name?: string, last_name?: ?string, username?: ?string}  $data
     * @return array{user: User, token: string, created: bool}
     */
    public function telegramLogin(array $data): array
    {
        if (config('services.telegram.verify_init_data')) {
            $verified = $this->initDataValidator->validate((string) ($data['init_data'] ?? ''));

            $telegramId = (int) $verified['id'];
            $firstName = (string) ($verified['first_name'] ?? 'Telegram User');
            $lastName = $verified['last_name'] ?? null;
            $username = $verified['username'] ?? null;
        } else {
            // Dev/test mode without a Telegram client.
            $telegramId = (int) ($data['telegram_id'] ?? 0);
            $firstName = (string) ($data['first_name'] ?? 'Telegram User');
            $lastName = $data['last_name'] ?? null;
            $username = $data['username'] ?? null;

            if ($telegramId <= 0) {
                throw ValidationException::withMessages([
                    'telegram_id' => ['The telegram id field is required.'],
                ]);
            }
        }

        $user = User::firstOrNew(['telegram_id' => $telegramId]);
        $isNew = ! $user->exists;

        // The name is editable in the mini app (PATCH /me), so Telegram only
        // seeds it — re-syncing on every launch would overwrite the user's edit.
        // The @username handle is not editable here, so it keeps following Telegram.
        if ($isNew || blank($user->first_name)) {
            $user->first_name = $firstName;
            $user->last_name = $lastName;
        }

        $user->username = $username;

        if ($isNew) {
            // Client is the base role every user holds from the start; the
            // onboarding role selection later adds a second role on top.
            $user->role = Role::Client;
            $user->roles = collect([Role::Client]);
            $user->is_active = true;
        }

        $user->save();

        // Covers the "phone shared before the manager pre-created the agency"
        // ordering — the webhook contact handler covers the opposite order.
        $this->agentLinker->linkByPhone($user);

        // The mini app authenticates from Telegram initData on every launch. Keep
        // the newest few mini app tokens (so a second device stays signed in)
        // and drop the rest; admin and other named tokens are not touched.
        $token = $user->createToken(self::MINI_APP_TOKEN_NAME)->plainTextToken;

        $this->pruneMiniAppTokens($user);

        return [
            'user' => $user,
            'token' => $token,
            'created' => $isNew,
        ];
    }

    /**
     * Revoke every mini app token beyond the MINI_APP_MAX_TOKENS newest ones.
     */
    private function pruneMiniAppTokens(User $user): void
    {
        $keep = $user->tokens()
            ->where('name', self::MINI_APP_TOKEN_NAME)
            ->orderByDesc('id')
            ->limit(self::MINI_APP_MAX_TOKENS)
            ->pluck('id');

        $user->tokens()
            ->where('name', self::MINI_APP_TOKEN_NAME)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * Authenticate an admin via email + password.
     *
     * Brute-force guard: wrong credentials are counted per email+IP; after
     * ADMIN_LOGIN_MAX_ATTEMPTS the pair is locked for ADMIN_LOGIN_LOCKOUT_SECONDS
     * (429), even for the right password. A successful login clears the counter.
     * This sits behind the per-IP route throttle, which a single attacker can
     * dodge by rotating IPs — the email dimension here does not care.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function adminLogin(string $email, string $password, ?string $ip = null): array
    {
        $throttleKey = $this->adminLoginThrottleKey($email, $ip);

        if (RateLimiter::tooManyAttempts($throttleKey, self::ADMIN_LOGIN_MAX_ATTEMPTS)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($throttleKey) / 60));

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Try again in {$minutes} minute(s)."],
            ])->status(429);
        }

        $user = User::where('email', $email)->first();

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, self::ADMIN_LOGIN_LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->role !== Role::Admin) {
            throw ValidationException::withMessages([
                'email' => ['Admin privileges are required.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is disabled.'],
            ]);
        }

        return [
            'user' => $user,
            // Admin sessions are short-lived (audit §2.2): the panel re-logs in
            // with the password; the mini app token keeps the global expiry.
            'token' => $user->createToken('admin', ['*'], now()->addMinutes(self::ADMIN_TOKEN_TTL_MINUTES))->plainTextToken,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    private function adminLoginThrottleKey(string $email, ?string $ip): string
    {
        return 'admin-login:'.sha1(Str::lower(trim($email)).'|'.($ip ?? 'unknown'));
    }
}
