<?php

namespace Tests\Feature\Pass;

use App\Enums\GatewayPaymentStatus;
use App\Enums\WalletTransactionType;
use App\Models\AgentPass;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\Pass\GatewayPaymentService;
use App\Services\Pass\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Full refund of a card payment: take back what it bought, reverse at the gateway. */
class GatewayRefundTest extends TestCase
{
    use RefreshDatabase;

    private const CARD = '8600 1234 5678 2365';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'passes.gateway' => 'fake', 'passes.price_som' => 1000, 'passes.hours' => 24,
            'passes.wallet_enabled' => true, 'passes.response_price_som' => 1000,
        ]);
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->approved()->create();

        return $user;
    }

    private function pay(User $agent, string $path, array $extra = []): GatewayPayment
    {
        app('auth')->forgetGuards();
        $token = $agent->createToken('t')->plainTextToken;
        $ref = $this->withToken($token)->postJson($path, ['card_number' => self::CARD, 'expiry' => '01/28'] + $extra)
            ->assertCreated()->json('data.payment_ref');
        $this->withToken($token)->postJson("/api/v1/agent/pass/card/{$ref}/confirm", ['otp' => '111111'])->assertOk();

        return GatewayPayment::query()->where('reference', $ref)->firstOrFail();
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        app('auth')->forgetGuards();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_refunds_a_wallet_top_up(): void
    {
        $agent = $this->agent();
        $payment = $this->pay($agent, '/api/v1/agent/wallet/card', ['amount_som' => 10000]);
        $this->assertSame(1_000_000, app(WalletService::class)->balanceTiyin($agent));

        $this->admin();
        $this->getJson('/api/v1/admin/gateway-payments')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $payment->id)
            ->assertJsonPath('data.items.0.can_refund', true);

        $this->postJson("/api/v1/admin/gateway-payments/{$payment->id}/refund", ['reason' => 'Mijoz so‘radi'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund.reason', 'Mijoz so‘radi');

        $this->assertSame(0, app(WalletService::class)->balanceTiyin($agent));

        // A second refund is refused and changes nothing.
        $this->postJson("/api/v1/admin/gateway-payments/{$payment->id}/refund", ['reason' => 'again'])->assertStatus(422);
        $this->assertSame(0, app(WalletService::class)->balanceTiyin($agent));
    }

    public function test_spent_top_up_cannot_be_refunded(): void
    {
        $agent = $this->agent();
        $payment = $this->pay($agent, '/api/v1/agent/wallet/card', ['amount_som' => 10000]);
        app(WalletService::class)->adjust($agent, -500_000, 'spent', User::factory()->admin()->create());

        $this->admin();
        $this->postJson("/api/v1/admin/gateway-payments/{$payment->id}/refund", ['reason' => 'test'])
            ->assertStatus(422);

        $this->assertSame(GatewayPaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(500_000, app(WalletService::class)->balanceTiyin($agent));
    }

    public function test_refunding_a_pass_payment_ends_that_pass(): void
    {
        $agent = $this->agent();
        $payment = $this->pay($agent, '/api/v1/agent/pass/card');
        $this->assertSame('active', AgentPass::query()->where('gateway_payment_id', $payment->id)->value('status'));

        $this->admin();
        $this->postJson("/api/v1/admin/gateway-payments/{$payment->id}/refund", ['reason' => 'xato to‘lov'])->assertOk();

        $this->assertSame('refunded', AgentPass::query()->where('gateway_payment_id', $payment->id)->value('status'));
    }

    public function test_gateway_refusal_rolls_everything_back(): void
    {
        $agent = $this->agent();
        $payment = $this->pay($agent, '/api/v1/agent/wallet/card', ['amount_som' => 10000]);
        $payment->update(['gateway_ref' => 'fake_card_no_reverse']);

        $admin = $this->admin();
        $this->postJson("/api/v1/admin/gateway-payments/{$payment->id}/refund", ['reason' => 'test'])->assertStatus(422);

        $this->assertSame(GatewayPaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(1_000_000, app(WalletService::class)->balanceTiyin($agent));
        $this->assertNotNull($admin);
    }

    public function test_non_admin_cannot_refund(): void
    {
        $agent = $this->agent();
        $payment = $this->pay($agent, '/api/v1/agent/wallet/card', ['amount_som' => 10000]);

        app('auth')->forgetGuards();
        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/admin/gateway-payments/{$payment->id}/refund", ['reason' => 'test'])->assertForbidden();
    }

    private function atmos(array $fakes): void
    {
        config([
            'passes.gateway' => 'atmos',
            'atmos.base_url' => 'https://atmos.test',
            'atmos.consumer_key' => 'key',
            'atmos.consumer_secret' => 'secret',
            'atmos.store_id' => 77,
        ]);
        Cache::flush();
        Http::fake(['atmos.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])] + $fakes);
    }

    public function test_atmos_reverse_uses_the_success_trans_id(): void
    {
        $ok = ['result' => ['code' => 'OK', 'description' => 'Нет ошибок']];
        $this->atmos([
            'atmos.test/merchant/pay/get' => Http::response($ok + ['store_transaction' => [
                'trans_id' => 4242, 'success_trans_id' => 9001, 'confirmed' => true, 'status_code' => '0', 'amount' => 1_000_000,
            ]]),
            'atmos.test/merchant/pay/reverse' => Http::response($ok + ['transaction_id' => 9001]),
        ]);

        $agent = $this->agent();
        $payment = GatewayPayment::query()->create([
            'reference' => (string) Str::uuid(), 'user_id' => $agent->id, 'purpose' => 'topup',
            'amount_tiyin' => 1_000_000, 'status' => GatewayPaymentStatus::Success, 'gateway' => 'atmos', 'gateway_ref' => 'card:4242',
        ]);
        app(WalletService::class)->credit($agent, WalletTransactionType::Topup, 1_000_000, 'gp:'.$payment->id);

        app(GatewayPaymentService::class)->refund($payment, User::factory()->admin()->create(), 'dev refund');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/merchant/pay/reverse')
            && $r['transaction_id'] === 9001 && $r['reason'] === 'dev refund');
        $this->assertSame(GatewayPaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_a_reversed_atmos_transaction_is_never_credited(): void
    {
        $ok = ['result' => ['code' => 'OK', 'description' => 'Нет ошибок']];
        // What the DEV store returns after merchant/pay/reverse: still confirmed, status_code -20.
        $this->atmos([
            'atmos.test/merchant/pay/get' => Http::response($ok + ['store_transaction' => [
                'trans_id' => 7070, 'success_trans_id' => 9002, 'confirmed' => true, 'status_code' => '-20', 'amount' => 1_000_000,
            ]]),
        ]);

        $agent = $this->agent();
        $payment = GatewayPayment::query()->create([
            'reference' => (string) Str::uuid(), 'user_id' => $agent->id, 'purpose' => 'topup',
            'amount_tiyin' => 1_000_000, 'status' => GatewayPaymentStatus::Pending, 'gateway' => 'atmos', 'gateway_ref' => 'card:7070',
        ]);

        $synced = app(GatewayPaymentService::class)->sync($payment);

        $this->assertSame(GatewayPaymentStatus::Failed, $synced->status);
        $this->assertSame(0, app(WalletService::class)->balanceTiyin($agent));
    }
}
