<?php

namespace Tests\Feature\Pass;

use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\GatewayPayment;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Pass\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Per-order fee on the otklik (Tender + Tezkor pay_to_respond), the wallet, admin settings. */
class PassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function auth(User $user): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        // Ready to work: a service category (the factory sets a map pin).
        AgentProfile::factory()->for($user)->approved()->create()
            ->categories()->attach(Category::factory()->create());

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function tezkor(?User $target = null): Order
    {
        return Order::factory()->status(OrderStatus::New)->create([
            'category_id' => null,
            'route' => OrderRoute::Tezkor,
            'payment_state' => OrderPaymentState::NotRequired,
            'target_agent_id' => $target?->id,
        ]);
    }

    private function claim(User $agent, Order $order)
    {
        return $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent));
    }

    private function fees(): void
    {
        config(['passes.enforce' => true, 'passes.wallet_enabled' => true]);
    }

    /** Top the wallet up end-to-end through the fake hosted checkout. */
    private function topup(User $agent, int $som): void
    {
        $r = $this->postJson('/api/v1/agent/wallet/topup', ['amount_som' => $som], $this->auth($agent))->assertCreated();
        $this->postJson($r->json('data.checkout_url'))->assertOk();
    }

    public function test_callback_is_idempotent(): void
    {
        config(['passes.wallet_enabled' => true]);
        $agent = $this->agent();
        $r = $this->postJson('/api/v1/agent/wallet/topup', ['amount_som' => 1000], $this->auth($agent))->assertCreated();
        $payment = GatewayPayment::first();
        $body = ['gateway_ref' => $payment->gateway_ref, 'status' => 'success', 'amount_tiyin' => 100000];

        $this->postJson('/api/v1/payments/gateway/callback', $body)->assertOk();
        $this->postJson('/api/v1/payments/gateway/callback', $body)->assertOk();
        $this->postJson($r->json('data.checkout_url'))->assertOk();

        $this->assertSame(100000, app(WalletService::class)->balanceTiyin($agent));
    }

    public function test_callback_with_wrong_amount_does_not_credit(): void
    {
        config(['passes.wallet_enabled' => true]);
        $agent = $this->agent();
        $this->postJson('/api/v1/agent/wallet/topup', ['amount_som' => 1000], $this->auth($agent))->assertCreated();
        $payment = GatewayPayment::first();

        $this->postJson('/api/v1/payments/gateway/callback', ['gateway_ref' => $payment->gateway_ref, 'status' => 'success', 'amount_tiyin' => 1])->assertOk();

        $this->assertSame(0, app(WalletService::class)->balanceTiyin($agent));
        $this->postJson('/api/v1/payments/gateway/callback', ['nonsense' => 1])->assertStatus(400);
    }

    /** A Propusk payment started before the pass was retired lands on the balance. */
    public function test_late_pass_payment_is_credited_to_the_balance(): void
    {
        $agent = $this->agent();
        $payment = GatewayPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'user_id' => $agent->id,
            'purpose' => GatewayPaymentPurpose::Pass,
            'amount_tiyin' => 100000,
            'status' => GatewayPaymentStatus::Pending,
            'gateway' => 'fake',
            'gateway_ref' => 'fake-old-pass',
            'meta' => ['hours' => 24],
        ]);

        $this->postJson('/api/v1/payments/gateway/callback', ['gateway_ref' => $payment->gateway_ref, 'status' => 'success', 'amount_tiyin' => 100000])->assertOk();

        $this->assertSame(100000, app(WalletService::class)->balanceTiyin($agent));
    }

    public function test_enforce_off_otklik_is_free(): void
    {
        config(['passes.enforce' => false, 'passes.wallet_enabled' => true]);
        $agent = $this->agent();

        $this->claim($agent, $this->tezkor())->assertCreated();
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_per_order_fee_charges_every_responding_agent(): void
    {
        $this->fees();
        $agent = $this->agent();
        $wallet = app(WalletService::class);
        $order = $this->tezkor();

        $this->claim($agent, $order)->assertStatus(402)->assertJsonPath('code', 'insufficient_balance');

        $wallet->credit($agent, WalletTransactionType::Topup, 250000, 'gp:test');
        $this->claim($agent, $order)->assertCreated();
        $this->assertSame(150000, $wallet->balanceTiyin($agent));
        $this->assertSame(100000, $order->offers()->where('agent_id', $agent->id)->value('fee_tiyin'));

        // No exclusive slot: a second paying agent responds to the same request.
        $other = $this->agent();
        $wallet->credit($other, WalletTransactionType::Topup, 100000, 'gp:test2');
        $this->claim($other, $order)->assertCreated();
        $this->assertSame(0, $wallet->balanceTiyin($other));
        $this->assertSame(2, $order->offers()->count());
    }

    public function test_directed_order_also_charges(): void
    {
        $this->fees();
        $agent = $this->agent();

        $this->claim($agent, $this->tezkor($agent))->assertStatus(402)->assertJsonPath('code', 'insufficient_balance');
    }

    public function test_tender_otklik_is_charged_the_same(): void
    {
        $this->fees();
        $agent = $this->agent();
        $wallet = app(WalletService::class);
        $wallet->credit($agent, WalletTransactionType::Topup, 150000, 'gp:tender');

        $tender = Order::factory()->status(OrderStatus::New)->create([
            'category_id' => null,
            'route' => OrderRoute::Tender,
            'payment_state' => OrderPaymentState::NotRequired,
        ]);

        $this->claim($agent, $tender)->assertCreated();
        $this->assertSame(50000, $wallet->balanceTiyin($agent));

        // A rejected otklik (duplicate) charges nothing.
        $this->claim($agent, $tender)->assertStatus(422);
        $this->assertSame(50000, $wallet->balanceTiyin($agent));

        $second = Order::factory()->status(OrderStatus::New)->create([
            'category_id' => null,
            'route' => OrderRoute::Tender,
            'payment_state' => OrderPaymentState::NotRequired,
        ]);
        $this->claim($agent, $second)
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_balance')
            ->assertJsonPath('data.price_som', 1000)
            ->assertJsonPath('data.balance_som', 500);
    }

    /** Per-order fees and top-ups appear in the admin summary and the balance ledger. */
    public function test_admin_sees_otklik_fees_and_topups(): void
    {
        $this->fees();
        $agent = $this->agent();
        app(WalletService::class)->credit($agent, WalletTransactionType::Topup, 500000, 'gp:admin-view');
        $order = $this->tezkor();
        $this->claim($agent, $order)->assertCreated();

        $admin = $this->admin();

        $this->getJson('/api/v1/admin/passes/summary', $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.responses.count', 1)
            ->assertJsonPath('data.responses.sum_som', 1000)
            ->assertJsonPath('data.takes.count', 0)
            ->assertJsonPath('data.topups.count', 1)
            ->assertJsonPath('data.topups.sum_som', 5000)
            ->assertJsonPath('data.revenue_som', 1000)
            ->assertJsonPath('data.per_day.0.responses_count', 1)
            ->assertJsonPath('data.per_day.0.responses_sum_som', 1000);

        $this->getJson('/api/v1/admin/passes/transactions?type=response_fee', $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.user_id', $agent->id)
            ->assertJsonPath('data.items.0.amount_som', -1000)
            ->assertJsonPath('data.items.0.order_id', $order->id);

        $this->getJson('/api/v1/admin/passes/transactions', $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        // Agents cannot read the ledger.
        $this->getJson('/api/v1/admin/passes/transactions', $this->auth($agent))->assertForbidden();
    }

    public function test_fees_without_wallet_are_payment_source_unavailable(): void
    {
        config(['passes.enforce' => true, 'passes.wallet_enabled' => false]);

        $this->claim($this->agent(), $this->tezkor())->assertStatus(402)->assertJsonPath('code', 'payment_source_unavailable');
    }

    /** No cap on how many requests one agent answers. */
    public function test_agent_can_respond_to_any_number_of_tezkor_requests(): void
    {
        $agent = $this->agent();

        foreach (range(1, 5) as $_) {
            $this->claim($agent, $this->tezkor())->assertCreated();
        }
    }

    public function test_wallet_never_negative_and_ledger_is_sum(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class);

        $wallet->credit($user, WalletTransactionType::Topup, 500000, 'a');
        $wallet->credit($user, WalletTransactionType::Topup, 500000, 'a'); // replay = no-op
        $wallet->debit($user, WalletTransactionType::ResponseFee, 200000);
        $this->assertSame(300000, $wallet->balanceTiyin($user));

        try {
            $wallet->debit($user, WalletTransactionType::ResponseFee, 300001);
            $this->fail('overdraw allowed');
        } catch (ValidationException) {
        }

        $this->assertSame(300000, $wallet->balanceTiyin($user));
        $this->assertSame(300000, (int) $user->fresh()->hasMany(WalletTransaction::class)->sum('amount_tiyin'));
    }

    public function test_wallet_dormant_when_disabled_and_used_when_enabled(): void
    {
        $agent = $this->agent();

        $this->postJson('/api/v1/agent/wallet/topup', ['amount_som' => 5000], $this->auth($agent))->assertUnprocessable();
        $this->assertSame(0, WalletTransaction::count());

        config(['passes.wallet_enabled' => true]);
        $this->topup($agent, 5000);
        $this->assertSame(500000, app(WalletService::class)->balanceTiyin($agent));
        $this->getJson('/api/v1/agent/pass', $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.balance_som', 5000)
            ->assertJsonPath('data.response_price_som', 1000)
            ->assertJsonPath('data.tezkor_billing', 'pay_to_respond');
    }

    public function test_admin_adjust_and_settings(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();

        $this->postJson("/api/v1/admin/users/{$agent->id}/wallet/adjust", ['amount_som' => 3000, 'reason' => 'Bonus'], $this->auth($admin))
            ->assertOk()->assertJsonPath('data.balance_som', 3000);
        $this->postJson("/api/v1/admin/users/{$agent->id}/wallet/adjust", ['amount_som' => -5000, 'reason' => 'Fix'], $this->auth($admin))
            ->assertUnprocessable();

        $this->getJson('/api/v1/admin/passes/settings', $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.tezkor_billing', 'pay_to_respond')
            ->assertJsonMissingPath('data.mode');

        $this->putJson('/api/v1/admin/passes/settings', [
            'response_price_som' => 2500,
            'tezkor_billing' => 'pay_on_pick',
            'pick_pay_minutes' => 45,
        ], $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.response_price_som', 2500)
            ->assertJsonPath('data.tezkor_billing', 'pay_on_pick')
            ->assertJsonPath('data.pick_pay_minutes', 45);

        $this->putJson('/api/v1/admin/passes/settings', ['tezkor_billing' => 'daily_pass'], $this->auth($admin))->assertUnprocessable();
        $this->putJson('/api/v1/admin/passes/settings', ['pick_pay_minutes' => 1], $this->auth($admin))->assertUnprocessable();

        $this->getJson('/api/v1/agent/pass', $this->auth($agent))
            ->assertJsonPath('data.response_price_som', 2500)
            ->assertJsonPath('data.tezkor_billing', 'pay_on_pick');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $agent = $this->agent();

        $this->getJson('/api/v1/admin/passes', $this->auth($agent))->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$agent->id}/wallet/adjust", ['amount_som' => 1, 'reason' => 'x y z'], $this->auth($agent))->assertForbidden();
        $this->putJson('/api/v1/admin/passes/settings', [], $this->auth($agent))->assertForbidden();
    }

    /** The daily Propusk is retired — its purchase endpoints are gone. */
    public function test_pass_purchase_endpoints_are_gone(): void
    {
        $agent = $this->agent();

        $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))->assertNotFound();
        $this->postJson("/api/v1/admin/users/{$agent->id}/pass", ['hours' => 1, 'reason' => 'x y z'], $this->auth($this->admin()))->assertNotFound();
    }
}
