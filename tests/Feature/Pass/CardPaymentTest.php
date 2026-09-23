<?php

namespace Tests\Feature\Pass;

use App\Enums\GatewayPaymentStatus;
use App\Models\AgentPass;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\Pass\GatewayPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** In-app card form for the Propusk: card → SMS code → confirm. */
class CardPaymentTest extends TestCase
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

    private function start(User $agent, string $card = self::CARD, string $expiry = '01/28'): TestResponse
    {
        return $this->as($agent)->postJson('/api/v1/agent/pass/card', ['card_number' => $card, 'expiry' => $expiry]);
    }

    public function test_card_then_sms_code_activates_the_pass(): void
    {
        $agent = $this->agent();

        $ref = $this->start($agent)
            ->assertCreated()
            ->assertJsonPath('data.amount_som', 1000)
            ->assertJsonPath('data.card_mask', '8600 •••• 2365')
            ->json('data.payment_ref');

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.summary.active', true);

        $this->assertSame(1, AgentPass::query()->where('user_id', $agent->id)->count());

        // Replaying the confirm never activates a second pass.
        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])->assertOk();
        $this->assertSame(1, AgentPass::query()->where('user_id', $agent->id)->count());
    }

    public function test_wrong_code_keeps_the_payment_open_for_a_retry(): void
    {
        $agent = $this->agent();
        $ref = $this->start($agent)->json('data.payment_ref');

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid');

        $this->assertSame(GatewayPaymentStatus::Pending, GatewayPayment::query()->firstOrFail()->status);

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])
            ->assertJsonPath('data.status', 'success');
    }

    public function test_declined_card_fails_the_payment(): void
    {
        $this->start($this->agent(), '8600 1234 5678 0000')
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_declined');

        $this->assertSame(GatewayPaymentStatus::Failed, GatewayPayment::query()->firstOrFail()->status);
    }

    public function test_card_data_is_validated(): void
    {
        $agent = $this->agent();

        $this->start($agent, '8600 1234')->assertStatus(422)->assertJsonValidationErrors('card_number');
        $this->start($agent, self::CARD, '13/28')->assertStatus(422)->assertJsonValidationErrors('expiry');
        $this->start($agent, self::CARD, '01/20')->assertStatus(422)->assertJsonValidationErrors('expiry');

        $this->assertSame(0, GatewayPayment::query()->count());
    }

    public function test_full_card_number_is_never_stored(): void
    {
        $this->start($this->agent())->assertCreated();

        $row = json_encode(GatewayPayment::query()->firstOrFail()->toArray());

        $this->assertStringNotContainsString('860012345678', (string) $row);
        $this->assertStringNotContainsString('0128', (string) $row);
    }

    public function test_only_the_payer_can_confirm(): void
    {
        $ref = $this->start($this->agent())->json('data.payment_ref');

        $this->as($this->agent())->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])
            ->assertNotFound();
    }

    public function test_unapproved_provider_cannot_pay(): void
    {
        $this->start(User::factory()->create())->assertForbidden();
    }

    public function test_wallet_top_up_by_card_credits_the_balance(): void
    {
        config(['passes.wallet_enabled' => true, 'passes.response_price_som' => 1000]);
        $agent = $this->agent();

        $ref = $this->as($agent)->postJson('/api/v1/agent/wallet/card', [
            'amount_som' => 10000, 'card_number' => self::CARD, 'expiry' => '01/28',
        ])->assertCreated()->assertJsonPath('data.amount_som', 10000)->json('data.payment_ref');

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.summary.balance_som', 10000);

        // No pass is bought by a top-up.
        $this->assertSame(0, AgentPass::query()->where('user_id', $agent->id)->count());
    }

    public function test_wallet_top_up_needs_the_wallet_and_a_minimum(): void
    {
        $agent = $this->agent();
        $body = ['amount_som' => 10000, 'card_number' => self::CARD, 'expiry' => '01/28'];

        config(['passes.wallet_enabled' => false]);
        $this->as($agent)->postJson('/api/v1/agent/wallet/card', $body)->assertStatus(422);

        config(['passes.wallet_enabled' => true, 'passes.response_price_som' => 1000]);
        $this->as($agent)->postJson('/api/v1/agent/wallet/card', ['amount_som' => 500] + $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_som');
    }

    public function test_atmos_merchant_flow(): void
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
        Http::fake([
            'atmos.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'atmos.test/merchant/pay/create' => Http::response($ok + ['transaction_id' => 4242]),
            'atmos.test/merchant/pay/pre-apply' => Http::response($ok + ['transaction_id' => 4242]),
            'atmos.test/merchant/pay/apply' => Http::response($ok + ['store_transaction' => [
                'success_trans_id' => 9001, 'trans_id' => 4242, 'amount' => 100000, 'confirmed' => true,
            ]]),
        ]);

        $agent = $this->agent();
        $ref = $this->start($agent, self::CARD, '01/28')->assertCreated()->json('data.payment_ref');

        $this->assertSame('card:4242', GatewayPayment::query()->firstOrFail()->gateway_ref);

        // ATMOS wants the expiry as YYmm: 01/28 → "2801".
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/merchant/pay/pre-apply')
            && $r['expiry'] === '2801'
            && $r['card_number'] === '8600123456782365'
            && $r['transaction_id'] === 4242);

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertSame(1, AgentPass::query()->where('user_id', $agent->id)->count());
    }

    public function test_atmos_wrong_code_and_status_sync(): void
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
        Http::fake([
            'atmos.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'atmos.test/merchant/pay/create' => Http::response($ok + ['transaction_id' => 5151]),
            'atmos.test/merchant/pay/pre-apply' => Http::response($ok),
            'atmos.test/merchant/pay/apply' => Http::response(['result' => ['code' => 'STPIMS-ERR-044', 'description' => 'Неверный код']]),
            'atmos.test/merchant/pay/get' => Http::response($ok + ['store_transaction' => ['confirmed' => true, 'amount' => 100000]]),
        ]);

        $agent = $this->agent();
        $ref = $this->start($agent)->json('data.payment_ref');

        $this->as($agent)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid')
            ->assertJsonPath('message', 'Неверный код');

        // The reconcile sweep reads card refs from merchant/pay/get, not invoice/get.
        $payment = app(GatewayPaymentService::class)->sync(GatewayPayment::query()->firstOrFail());

        $this->assertSame(GatewayPaymentStatus::Success, $payment->status);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/checkout/invoice/get'));
    }
}
