<?php

namespace Tests\Feature\Api\V1\Telegram;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:test-token',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.mini_app_url' => 'https://app.example.test',
        ]);

        // Catch-all: the handler calls sendMessage and setChatMenuButton.
        Http::fake([
            '*' => Http::response(['ok' => true]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function postWebhook(array $update, ?string $secret = self::SECRET): TestResponse
    {
        $headers = $secret !== null
            ? ['X-Telegram-Bot-Api-Secret-Token' => $secret]
            : [];

        return $this->postJson('/api/v1/telegram/webhook', $update, $headers);
    }

    public function test_webhook_rejects_missing_or_wrong_secret(): void
    {
        $this->postWebhook(['message' => []], secret: null)->assertForbidden();
        $this->postWebhook(['message' => []], secret: 'wrong')->assertForbidden();
    }

    public function test_start_command_hides_menu_button_and_requests_contact(): void
    {
        $this->postWebhook([
            'message' => [
                'chat' => ['id' => 555],
                'from' => ['id' => 555, 'first_name' => 'Ali'],
                'text' => '/start',
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        // Asks for the phone with a request_contact keyboard.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && ($request['reply_markup']['keyboard'][0][0]['request_contact'] ?? null) === true;
        });

        // Hides the mini app launcher until the phone is shared.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/setChatMenuButton')
                && ($request['menu_button']['type'] ?? null) === 'default';
        });
    }

    public function test_start_for_user_with_phone_opens_app_directly(): void
    {
        User::factory()->create(['telegram_id' => 444, 'phone' => '+998901112233']);

        $this->postWebhook([
            'message' => [
                'chat' => ['id' => 444],
                'from' => ['id' => 444, 'first_name' => 'Ali'],
                'text' => '/start',
            ],
        ])->assertOk();

        // No phone request — instead the app launcher + inline web_app button.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/setChatMenuButton')
                && ($request['menu_button']['type'] ?? null) === 'web_app';
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && isset($request['reply_markup']['inline_keyboard'][0][0]['web_app']);
        });
        Http::assertNotSent(function ($request) {
            return ($request['reply_markup']['keyboard'][0][0]['request_contact'] ?? null) === true;
        });
    }

    public function test_shared_contact_saves_and_normalizes_phone_for_existing_user(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 777,
            'phone' => null,
        ]);

        $this->postWebhook([
            'message' => [
                'chat' => ['id' => 777],
                'from' => ['id' => 777, 'first_name' => 'Ali'],
                'contact' => [
                    'phone_number' => '998 90 111 22 33',
                    'user_id' => 777,
                    'first_name' => 'Ali',
                ],
            ],
        ])->assertOk();

        $this->assertSame('+998901112233', $user->refresh()->phone);

        // After the phone is saved, the mini app launcher is revealed.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/setChatMenuButton')
                && ($request['menu_button']['type'] ?? null) === 'web_app';
        });
    }

    public function test_shared_contact_creates_user_when_not_registered_yet(): void
    {
        $this->postWebhook([
            'message' => [
                'chat' => ['id' => 888],
                'from' => ['id' => 888, 'first_name' => 'Vali', 'username' => 'vali'],
                'contact' => [
                    'phone_number' => '+998901234567',
                    'user_id' => 888,
                    'first_name' => 'Vali',
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'telegram_id' => 888,
            'phone' => '+998901234567',
            'role' => 'client',
        ]);
    }

    public function test_contact_for_another_user_is_ignored(): void
    {
        $this->postWebhook([
            'message' => [
                'chat' => ['id' => 999],
                'from' => ['id' => 999, 'first_name' => 'Ali'],
                'contact' => [
                    'phone_number' => '+998900000000',
                    'user_id' => 1234, // shared someone else's contact
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['phone' => '+998900000000']);
    }
}
