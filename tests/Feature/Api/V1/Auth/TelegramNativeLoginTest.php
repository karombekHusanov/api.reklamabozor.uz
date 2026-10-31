<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramNativeLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:test-token',
            'services.telegram.bot_username' => 'prb_test_bot',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.mini_app_url' => 'https://app.example.test',
        ]);

        Http::fake(['*' => Http::response(['ok' => true])]);
    }

    /** @return array{code: string, poll_secret: string, bot_url: string} */
    private function start(): array
    {
        return $this->postJson('/api/v1/auth/telegram/native/start')
            ->assertCreated()
            ->json('data');
    }

    private function poll(string $code, string $secret): TestResponse
    {
        return $this->postJson('/api/v1/auth/telegram/native/poll', ['code' => $code, 'poll_secret' => $secret]);
    }

    /** @param  array<string, mixed>  $update */
    private function webhook(array $update): void
    {
        $this->postJson('/api/v1/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function startCommand(int $tgId, string $code): void
    {
        $this->webhook(['message' => [
            'message_id' => 10,
            'chat' => ['id' => $tgId],
            'from' => ['id' => $tgId, 'first_name' => 'Ali'],
            'text' => "/start login_{$code}",
        ]]);
    }

    private function tap(int $tgId, string $data): void
    {
        $this->webhook(['callback_query' => [
            'id' => 'cb1',
            'from' => ['id' => $tgId],
            'data' => $data,
            'message' => ['message_id' => 11, 'chat' => ['id' => $tgId]],
        ]]);
    }

    public function test_start_returns_deep_link_and_poll_waits(): void
    {
        $login = $this->start();

        $this->assertSame("https://t.me/prb_test_bot?start=login_{$login['code']}", $login['bot_url']);
        $this->poll($login['code'], $login['poll_secret'])->assertStatus(202)->assertJsonPath('data.status', 'pending');
    }

    public function test_known_user_confirms_in_bot_and_app_gets_token_once(): void
    {
        $user = User::factory()->create(['telegram_id' => 777, 'phone' => '+998901112233']);
        $login = $this->start();

        $this->startCommand(777, $login['code']);
        // The bot asks for an explicit confirmation with inline buttons.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
            && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'login_ok:'.$login['code']);
        $this->poll($login['code'], $login['poll_secret'])->assertStatus(202);

        $this->tap(777, 'login_ok:'.$login['code']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/editMessageText'));

        $token = $this->poll($login['code'], $login['poll_secret'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.user.id', $user->id)
            ->json('data.token');

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('data.id', $user->id);
        // Redeemable once.
        $this->poll($login['code'], $login['poll_secret'])->assertStatus(410);
    }

    public function test_new_user_shares_phone_which_confirms_login(): void
    {
        $login = $this->start();

        $this->startCommand(888, $login['code']);
        Http::assertSent(fn ($r) => ($r['reply_markup']['keyboard'][0][0]['request_contact'] ?? null) === true);

        $this->webhook(['message' => [
            'chat' => ['id' => 888],
            'from' => ['id' => 888, 'first_name' => 'Vali'],
            'contact' => ['user_id' => 888, 'phone_number' => '998935556677', 'first_name' => 'Vali'],
        ]]);

        $this->poll($login['code'], $login['poll_secret'])
            ->assertOk()
            ->assertJsonPath('data.user.phone', '+998935556677');
        $this->assertDatabaseHas('users', ['telegram_id' => 888, 'phone' => '+998935556677']);
    }

    public function test_cancel_in_bot_ends_the_request(): void
    {
        User::factory()->create(['telegram_id' => 777, 'phone' => '+998901112233']);
        $login = $this->start();

        $this->startCommand(777, $login['code']);
        $this->tap(777, 'login_no:'.$login['code']);

        $this->poll($login['code'], $login['poll_secret'])->assertStatus(410)->assertJsonPath('errors.code.0', 'cancelled');
    }

    public function test_wrong_secret_never_yields_a_token(): void
    {
        User::factory()->create(['telegram_id' => 777, 'phone' => '+998901112233']);
        $login = $this->start();
        $this->startCommand(777, $login['code']);
        $this->tap(777, 'login_ok:'.$login['code']);

        $this->poll($login['code'], str_repeat('x', 48))->assertStatus(410);
        // The real device can still redeem it.
        $this->poll($login['code'], $login['poll_secret'])->assertOk();
    }

    public function test_inactive_user_cannot_confirm(): void
    {
        User::factory()->create(['telegram_id' => 777, 'phone' => '+998901112233', 'is_active' => false]);
        $login = $this->start();

        $this->startCommand(777, $login['code']);
        $this->tap(777, 'login_ok:'.$login['code']);

        $this->poll($login['code'], $login['poll_secret'])->assertStatus(202);
    }

    public function test_unknown_code_in_bot_says_expired(): void
    {
        $this->startCommand(777, str_repeat('a', 32));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'eskirgan'));
    }
}
