<?php

namespace Tests\Feature\Pass;

use App\Enums\OrderPaymentState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Models\AgentPass;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Pass\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

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
        AgentProfile::factory()->for($user)->approved()->create();

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

    /** Buy a pass end-to-end through the fake gateway. */
    private function buyPass(User $agent): void
    {
        $r = $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))->assertCreated();
        $this->getJson($r->json('data.checkout_url'))->assertOk();
    }

    public function test_purchase_creates_pending_payment_and_completion_activates_pass(): void
    {
        $agent = $this->agent();

        $r = $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))
            ->assertCreated()
            ->assertJsonPath('data.activated', false);
        $this->assertNotNull($r->json('data.checkout_url'));
        $this->assertSame(0, AgentPass::count());

        $this->getJson('/api/v1/agent/pass', $this->auth($agent))
            ->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.price_som', 1000);

        $this->postJson($r->json('data.checkout_url'))->assertOk();

        $this->getJson('/api/v1/agent/pass', $this->auth($agent))
            ->assertJsonPath('data.active', true)->assertJsonPath('data.hours', 24);
        $this->assertSame(100000, AgentPass::first()->price_tiyin);
    }

    public function test_second_purchase_extends_the_active_pass(): void
    {
        $agent = $this->agent();
        $this->buyPass($agent);
        $first = AgentPass::first();
        $this->buyPass($agent);

        $this->assertSame(2, AgentPass::count());
        $second = AgentPass::orderByDesc('id')->first();
        $this->assertTrue($second->starts_at->equalTo($first->expires_at));
        $this->assertTrue($second->expires_at->equalTo($first->expires_at->copy()->addHours(24)));

        $seconds = $this->getJson('/api/v1/agent/pass', $this->auth($agent))->json('data.seconds_left');
        $this->assertGreaterThan(47 * 3600, $seconds);
        $this->assertSame(2, count($this->getJson('/api/v1/agent/pass/history', $this->auth($agent))->json('data.items')));
    }

    public function test_callback_is_idempotent(): void
    {
        $agent = $this->agent();
        $r = $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))->assertCreated();
        $payment = GatewayPayment::first();
        $body = ['gateway_ref' => $payment->gateway_ref, 'status' => 'success', 'amount_tiyin' => 100000];

        $this->postJson('/api/v1/payments/gateway/callback', $body)->assertOk();
        $this->postJson('/api/v1/payments/gateway/callback', $body)->assertOk();
        $this->postJson($r->json('data.checkout_url'))->assertOk();

        $this->assertSame(1, AgentPass::count());
    }

    public function test_callback_with_wrong_amount_does_not_activate(): void
    {
        $agent = $this->agent();
        $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))->assertCreated();
        $payment = GatewayPayment::first();

        $this->postJson('/api/v1/payments/gateway/callback', ['gateway_ref' => $payment->gateway_ref, 'status' => 'success', 'amount_tiyin' => 1])->assertOk();

        $this->assertSame(0, AgentPass::count());
        $this->postJson('/api/v1/payments/gateway/callback', ['nonsense' => 1])->assertStatus(400);
    }

    public function test_enforce_off_claim_works_without_a_pass(): void
    {
        config(['passes.enforce' => false]);

        $this->claim($this->agent(), $this->tezkor())->assertCreated();
    }

    public function test_enforce_on_requires_pass_then_works_after_purchase(): void
    {
        config(['passes.enforce' => true]);
        $agent = $this->agent();
        $order = $this->tezkor();

        $this->claim($agent, $order)->assertStatus(402)->assertJsonPath('code', 'pass_required');
        $this->assertSame(0, $order->offers()->count());

        $this->buyPass($agent);

        $this->claim($agent, $order)->assertCreated();
        $this->assertSame(1, $order->offers()->where('agent_id', $agent->id)->count());
    }

    public function test_directed_order_also_requires_pass(): void
    {
        config(['passes.enforce' => true]);
        $agent = $this->agent();
        $order = $this->tezkor($agent);

        $this->claim($agent, $order)->assertStatus(402)->assertJsonPath('code', 'pass_required');
    }

    public function test_expired_pass_is_not_enough(): void
    {
        config(['passes.enforce' => true]);
        $agent = $this->agent();
        AgentPass::create([
            'user_id' => $agent->id, 'starts_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
            'price_tiyin' => 100000, 'source' => 'gateway', 'status' => 'active',
        ]);

        $this->claim($agent, $this->tezkor())->assertStatus(402);
    }

    public function test_per_response_charges_every_responding_agent(): void
    {
        config(['passes.enforce' => true, 'passes.mode' => 'per_response', 'passes.wallet_enabled' => true]);
        $agent = $this->agent();
        $wallet = app(WalletService::class);
        $order = $this->tezkor();

        $this->claim($agent, $order)->assertStatus(402)->assertJsonPath('code', 'insufficient_balance');

        $wallet->credit($agent, WalletTransactionType::Topup, 250000, 'gp:test');
        $this->claim($agent, $order)->assertCreated();
        $this->assertSame(150000, $wallet->balanceTiyin($agent));

        // No exclusive slot: a second paying agent responds to the same request.
        $other = $this->agent();
        $wallet->credit($other, WalletTransactionType::Topup, 100000, 'gp:test2');
        $this->claim($other, $order)->assertCreated();
        $this->assertSame(0, $wallet->balanceTiyin($other));
        $this->assertSame(2, $order->offers()->count());
    }

    public function test_per_response_charges_tender_otklik_the_same(): void
    {
        config(['passes.enforce' => true, 'passes.mode' => 'per_response', 'passes.wallet_enabled' => true]);
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

        // A rejected otklik (duplicate) rolls the fee back with it.
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

    /** Per-otklik fees and top-ups appear in the admin summary and the balance ledger. */
    public function test_admin_sees_otklik_fees_and_topups(): void
    {
        config(['passes.enforce' => true, 'passes.mode' => 'per_response', 'passes.wallet_enabled' => true]);
        $agent = $this->agent();
        app(WalletService::class)->credit($agent, WalletTransactionType::Topup, 500000, 'gp:admin-view');
        $order = $this->tezkor();
        $this->claim($agent, $order)->assertCreated();

        $admin = $this->admin();

        $this->getJson('/api/v1/admin/passes/summary', $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.responses.count', 1)
            ->assertJsonPath('data.responses.sum_som', 1000)
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

    public function test_per_response_without_wallet_is_payment_source_unavailable(): void
    {
        config(['passes.enforce' => true, 'passes.mode' => 'per_response', 'passes.wallet_enabled' => false]);

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
        $wallet->debit($user, WalletTransactionType::Pass, 200000);
        $this->assertSame(300000, $wallet->balanceTiyin($user));

        try {
            $wallet->debit($user, WalletTransactionType::Pass, 300001);
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
        $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))
            ->assertJsonPath('data.activated', false); // gateway path, no debit
        $this->assertSame(0, WalletTransaction::count());

        config(['passes.wallet_enabled' => true]);
        $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))
            ->assertStatus(402)->assertJsonPath('code', 'insufficient_balance');

        $t = $this->postJson('/api/v1/agent/wallet/topup', ['amount_som' => 5000], $this->auth($agent))->assertCreated();
        $this->postJson($t->json('data.checkout_url'))->assertOk();
        $this->assertSame(500000, app(WalletService::class)->balanceTiyin($agent));

        $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth($agent))
            ->assertCreated()->assertJsonPath('data.activated', true);
        $this->assertSame(400000, app(WalletService::class)->balanceTiyin($agent));
        $this->assertSame('wallet', AgentPass::first()->source);
    }

    public function test_admin_grant_adjust_list_summary_and_settings(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();

        $this->postJson("/api/v1/admin/users/{$agent->id}/pass", ['hours' => 48, 'reason' => 'Promo'], $this->auth($admin))
            ->assertCreated()->assertJsonPath('data.price_som', 0)->assertJsonPath('data.source', 'admin');
        $this->postJson("/api/v1/admin/users/{$agent->id}/pass", ['hours' => 48], $this->auth($admin))->assertUnprocessable();

        $this->postJson("/api/v1/admin/users/{$agent->id}/wallet/adjust", ['amount_som' => 3000, 'reason' => 'Bonus'], $this->auth($admin))
            ->assertOk()->assertJsonPath('data.balance_som', 3000);
        $this->postJson("/api/v1/admin/users/{$agent->id}/wallet/adjust", ['amount_som' => -5000, 'reason' => 'Fix'], $this->auth($admin))
            ->assertUnprocessable();

        $this->buyPass($agent);

        $this->getJson("/api/v1/admin/passes?user_id={$agent->id}", $this->auth($admin))
            ->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/admin/passes?source=gateway', $this->auth($admin))
            ->assertJsonPath('data.meta.total', 1);

        $s = $this->getJson('/api/v1/admin/passes/summary', $this->auth($admin))->assertOk();
        $s->assertJsonPath('data.count', 1)->assertJsonPath('data.sum_som', 1000)->assertJsonPath('data.granted_count', 1);
        $this->assertCount(1, $s->json('data.per_day'));

        $this->putJson('/api/v1/admin/passes/settings', ['price_som' => 2500], $this->auth($admin))
            ->assertOk()->assertJsonPath('data.price_som', 2500);
        $this->getJson('/api/v1/agent/pass', $this->auth($agent))->assertJsonPath('data.price_som', 2500);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $agent = $this->agent();

        $this->getJson('/api/v1/admin/passes', $this->auth($agent))->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$agent->id}/pass", ['hours' => 1, 'reason' => 'x y z'], $this->auth($agent))->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$agent->id}/wallet/adjust", ['amount_som' => 1, 'reason' => 'x y z'], $this->auth($agent))->assertForbidden();
        $this->putJson('/api/v1/admin/passes/settings', [], $this->auth($agent))->assertForbidden();
    }

    public function test_unapproved_user_cannot_purchase(): void
    {
        $this->postJson('/api/v1/agent/pass/purchase', [], $this->auth(User::factory()->create()))->assertForbidden();
    }
}
