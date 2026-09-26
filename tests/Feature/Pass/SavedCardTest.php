<?php

namespace Tests\Feature\Pass;

use App\Enums\GatewayPaymentStatus;
use App\Models\AgentPass;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\SavedCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Card binding: "save card" once (one SMS), then pay by token with no card data and no SMS. */
class SavedCardTest extends TestCase
{
    use RefreshDatabase;

    private const CARD = '8600 1234 5678 2365';

    protected function setUp(): void
    {
        parent::setUp();

        config(['passes.gateway' => 'fake', 'passes.price_som' => 1000, 'passes.hours' => 24]);
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->approved()->create();

        return $user;
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function saveCard(User $agent): SavedCard
    {
        $ref = $this->as($agent)->postJson('/api/v1/agent/pass/card', [
            'card_number' => self::CARD, 'expiry' => '01/28', 'save_card' => true,
        ])->assertCreated()->assertJsonPath('data.requires_otp', true)->json('data.payment_ref');

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        return SavedCard::query()->where('user_id', $agent->id)->firstOrFail();
    }

    public function test_save_card_binds_and_pays_with_one_sms(): void
    {
        $agent = $this->agent();
        $card = $this->saveCard($agent);

        $this->assertSame('8600 •••• 2365', $card->pan_mask);
        $this->assertSame(1, AgentPass::query()->where('user_id', $agent->id)->count());
        $this->assertSame($card->id, GatewayPayment::query()->firstOrFail()->meta['saved_card_id']);

        $this->as($agent)->getJson('/api/v1/agent/cards')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $card->id)
            ->assertJsonPath('data.items.0.card_mask', '8600 •••• 2365')
            ->assertJsonMissingPath('data.items.0.card_token');
    }

    public function test_saved_card_pays_at_once_without_sms(): void
    {
        config(['passes.wallet_enabled' => true, 'passes.response_price_som' => 1000]);
        $agent = $this->agent();
        $card = $this->saveCard($agent);

        $this->as($agent)->postJson('/api/v1/agent/wallet/card', ['amount_som' => 5000, 'card_id' => $card->id])
            ->assertCreated()
            ->assertJsonPath('data.requires_otp', false)
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.card_mask', '8600 •••• 2365')
            ->assertJsonPath('data.summary.balance_som', 5000);

        $this->assertNotNull($card->refresh()->last_used_at);
    }

    public function test_token_is_encrypted_and_card_number_never_stored(): void
    {
        $agent = $this->agent();
        $card = $this->saveCard($agent);

        $raw = (string) DB::table('saved_cards')->where('id', $card->id)->value('card_token');
        $this->assertStringNotContainsString($card->card_token, $raw);

        $dump = json_encode([DB::table('saved_cards')->get(), DB::table('gateway_payments')->get()]);
        $this->assertStringNotContainsString('860012345678', (string) $dump);
    }

    public function test_declined_token_charge_fails_the_payment(): void
    {
        $agent = $this->agent();
        $card = $this->saveCard($agent);
        $card->update(['card_token' => 'fake_declined_x']);

        $this->as($agent)->postJson('/api/v1/agent/pass/card', ['card_id' => $card->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_declined');

        $this->assertSame(GatewayPaymentStatus::Failed, GatewayPayment::query()->latest('id')->firstOrFail()->status);
    }

    public function test_another_users_card_cannot_be_used_or_removed(): void
    {
        $card = $this->saveCard($this->agent());
        $other = $this->agent();

        $this->as($other)->postJson('/api/v1/agent/pass/card', ['card_id' => $card->id])->assertStatus(422);
        $this->as($other)->deleteJson("/api/v1/agent/cards/{$card->id}")->assertNotFound();
        $this->as($other)->getJson('/api/v1/agent/cards')->assertJsonCount(0, 'data.items');

        $this->assertNotNull($card->fresh());
    }

    public function test_card_can_be_removed(): void
    {
        $agent = $this->agent();
        $card = $this->saveCard($agent);

        $this->as($agent)->deleteJson("/api/v1/agent/cards/{$card->id}")->assertOk();

        $this->assertNull($card->fresh());
        $this->as($agent)->postJson('/api/v1/agent/pass/card', ['card_id' => $card->id])->assertStatus(422);
    }

    public function test_card_id_cannot_be_mixed_with_card_data(): void
    {
        $agent = $this->agent();
        $card = $this->saveCard($agent);

        $this->as($agent)->postJson('/api/v1/agent/pass/card', [
            'card_id' => $card->id, 'card_number' => self::CARD, 'expiry' => '01/28',
        ])->assertStatus(422)->assertJsonValidationErrors('card_id');
    }

    public function test_wrong_code_on_binding_keeps_it_open_and_saves_nothing(): void
    {
        $agent = $this->agent();
        $ref = $this->as($agent)->postJson('/api/v1/agent/pass/card', [
            'card_number' => self::CARD, 'expiry' => '01/28', 'save_card' => true,
        ])->json('data.payment_ref');

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid');

        $this->assertSame(0, SavedCard::query()->count());

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])
            ->assertJsonPath('data.status', 'success');
        $this->assertSame(1, SavedCard::query()->count());
    }

    public function test_atmos_bind_then_token_payment(): void
    {
        config([
            'passes.gateway' => 'atmos',
            'atmos.base_url' => 'https://atmos.test',
            'atmos.consumer_key' => 'key',
            'atmos.consumer_secret' => 'secret',
            'atmos.store_id' => 77,
        ]);
        Cache::flush();

        $ok = ['result' => ['code' => 'OK', 'description' => 'Нет ошибок']];
        $tx = 6000;
        Http::fake([
            'atmos.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'atmos.test/partner/bind-card/init' => Http::response($ok + ['transaction_id' => 442, 'phone' => '********9999']),
            'atmos.test/partner/bind-card/confirm' => Http::response($ok + ['data' => [
                'card_id' => 1579076, 'pan' => '860012******2365', 'expiry' => '2801', 'card_token' => 'TOKEN-abc',
            ]]),
            'atmos.test/merchant/pay/create' => function () use (&$tx, $ok) {
                return Http::response($ok + ['transaction_id' => ++$tx]);
            },
            'atmos.test/merchant/pay/pre-apply' => Http::response($ok),
            'atmos.test/merchant/pay/apply' => Http::response($ok + ['store_transaction' => [
                'success_trans_id' => 9001, 'confirmed' => true,
            ]]),
            'atmos.test/partner/remove-card' => Http::response($ok),
        ]);

        $agent = $this->agent();
        $card = $this->saveCard($agent);

        $this->assertSame('1579076', $card->card_id);
        $this->assertSame('TOKEN-abc', $card->card_token);
        $this->assertSame('8600 •••• 2365', $card->pan_mask);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/partner/bind-card/init')
            && $r['card_number'] === '8600123456782365' && $r['expiry'] === '2801');
        // One SMS only: no card pre-apply, the charge goes by token with the fixed OTP.
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/merchant/pay/pre-apply') && isset($r['card_number']));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/merchant/pay/pre-apply')
            && $r['card_token'] === 'TOKEN-abc' && $r['transaction_id'] === 6001);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/merchant/pay/apply') && $r['otp'] === '111111');

        // Next payment by token only.
        $this->as($agent)->postJson('/api/v1/agent/pass/card', ['card_id' => $card->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'success');

        $this->as($agent)->deleteJson("/api/v1/agent/cards/{$card->id}")->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/partner/remove-card')
            && $r['id'] === 1579076 && $r['token'] === 'TOKEN-abc');
    }
}
