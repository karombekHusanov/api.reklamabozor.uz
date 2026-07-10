<?php

namespace Tests\Feature\Api\V1\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramInitDataTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456:test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'services.telegram.verify_init_data' => true,
        ]);
    }

    /**
     * Build initData signed exactly the way Telegram does.
     *
     * @param  array<string, mixed>  $user
     */
    private function signedInitData(array $user, ?int $authDate = null): string
    {
        $pairs = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAF0d3VabCdEf',
            'user' => json_encode($user),
        ];

        ksort($pairs);
        $dataCheckString = implode("\n", array_map(
            fn (string $key) => "{$key}={$pairs[$key]}",
            array_keys($pairs),
        ));

        $secretKey = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $pairs['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($pairs);
    }

    public function test_valid_init_data_authenticates_and_ignores_spoofed_fields(): void
    {
        $initData = $this->signedInitData([
            'id' => 555000111,
            'first_name' => 'Real',
            'last_name' => 'User',
            'username' => 'real_user',
        ]);

        // The spoofed plain fields must be ignored — identity comes from
        // the verified initData only.
        $this->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
            'telegram_id' => 999999999,
            'first_name' => 'Attacker',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.telegram_id', 555000111)
            ->assertJsonPath('data.user.first_name', 'Real')
            ->assertJsonPath('data.user.username', 'real_user');

        $this->assertDatabaseMissing('users', ['telegram_id' => 999999999]);
    }

    public function test_login_without_init_data_is_rejected_when_verification_is_on(): void
    {
        $this->postJson('/api/v1/auth/telegram', [
            'telegram_id' => 123123123,
            'first_name' => 'Attacker',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['init_data']);

        $this->assertDatabaseMissing('users', ['telegram_id' => 123123123]);
    }

    public function test_tampered_init_data_is_rejected(): void
    {
        $initData = $this->signedInitData(['id' => 555000111, 'first_name' => 'Real']);

        // Swap the user id inside the signed payload — the hash no longer matches.
        $tampered = str_replace('555000111', '666000222', $initData);

        $this->postJson('/api/v1/auth/telegram', ['init_data' => $tampered])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['init_data']);
    }

    public function test_expired_init_data_is_rejected(): void
    {
        $initData = $this->signedInitData(
            ['id' => 555000111, 'first_name' => 'Real'],
            now()->subDays(2)->timestamp,
        );

        $this->postJson('/api/v1/auth/telegram', ['init_data' => $initData])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['init_data']);
    }

    public function test_wrong_bot_token_signature_is_rejected(): void
    {
        // Signed with the test token, verified against a different one.
        $initData = $this->signedInitData(['id' => 555000111, 'first_name' => 'Real']);
        config(['services.telegram.bot_token' => 'different:token']);

        $this->postJson('/api/v1/auth/telegram', ['init_data' => $initData])
            ->assertUnprocessable();
    }
}
