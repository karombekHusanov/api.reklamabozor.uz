<?php

namespace App\Services\Auth;

use Illuminate\Validation\ValidationException;

/**
 * Verifies Telegram Mini App initData per the official spec:
 * secret_key = HMAC_SHA256(key: "WebAppData", data: bot_token),
 * hash       = HMAC_SHA256(key: secret_key, data: data_check_string).
 *
 * Without this check anyone who knows the API URL could mint a session for
 * any telegram_id — full account takeover.
 */
class TelegramInitDataValidator
{
    /**
     * Returns the verified `user` payload from initData.
     *
     * @return array{id: int, first_name?: string, last_name?: string, username?: string}
     *
     * @throws ValidationException
     */
    public function validate(string $initData): array
    {
        $botToken = (string) config('services.telegram.bot_token');

        if ($botToken === '') {
            throw ValidationException::withMessages([
                'init_data' => ['Telegram authentication is not configured.'],
            ]);
        }

        parse_str($initData, $pairs);

        $hash = (string) ($pairs['hash'] ?? '');
        unset($pairs['hash']);

        if ($hash === '' || $pairs === []) {
            $this->fail();
        }

        ksort($pairs);
        $dataCheckString = implode("\n", array_map(
            fn (string $key) => "{$key}={$pairs[$key]}",
            array_keys($pairs),
        ));

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expected, $hash)) {
            $this->fail();
        }

        // Replayed initData expires after the configured window (default 24h).
        $maxAge = (int) config('services.telegram.init_data_max_age', 86400);
        $authDate = (int) ($pairs['auth_date'] ?? 0);
        if ($authDate <= 0 || now()->timestamp - $authDate > $maxAge) {
            $this->fail();
        }

        $user = json_decode((string) ($pairs['user'] ?? ''), true);

        if (! is_array($user) || ! isset($user['id'])) {
            $this->fail();
        }

        return $user;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages([
            'init_data' => ['Telegram authentication data is invalid.'],
        ]);
    }
}
