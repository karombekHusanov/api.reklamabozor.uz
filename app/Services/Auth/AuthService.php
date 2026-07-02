<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Authenticate or register a Telegram user.
     *
     * @param  array{telegram_id: int, phone?: ?string, first_name: string, last_name?: ?string, username?: ?string}  $data
     * @return array{user: User, token: string, created: bool}
     */
    public function telegramLogin(array $data): array
    {
        $user = User::firstOrNew(['telegram_id' => $data['telegram_id']]);
        $isNew = ! $user->exists;

        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'] ?? null;
        $user->username = $data['username'] ?? null;

        // Only set phone when the caller actually provides one — never overwrite a
        // previously captured phone (e.g. shared via the bot) with null on re-login.
        if (! empty($data['phone'])) {
            $user->phone = $data['phone'];
        }

        if ($isNew) {
            $user->role = Role::Client;
            $user->is_active = true;
        }

        $user->save();

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
