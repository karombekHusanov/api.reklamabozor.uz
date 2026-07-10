<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Services\Agent\AgentAccountLinker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
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

        $user->first_name = $firstName;
        $user->last_name = $lastName;
        $user->username = $username;

        if ($isNew) {
            $user->role = Role::Client;
            $user->is_active = true;
        }

        $user->save();

        // Covers the "phone shared before the manager pre-created the agency"
        // ordering — the webhook contact handler covers the opposite order.
        $this->agentLinker->linkByPhone($user);

        // The mini app authenticates from Telegram initData on every launch, so keep a single
        // active token per user — otherwise personal_access_tokens would grow without bound.
        $user->tokens()->delete();

        return [
            'user' => $user,
            'token' => $user->createToken('auth')->plainTextToken,
            'created' => $isNew,
        ];
    }

    /**
     * Authenticate an admin via email + password.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function adminLogin(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

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
            'token' => $user->createToken('admin')->plainTextToken,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
