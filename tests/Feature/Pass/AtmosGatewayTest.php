<?php

namespace Tests\Feature\Pass;

use App\Enums\GatewayPaymentStatus;
use App\Models\AgentPass;
use App\Models\AgentProfile;
use App\Models\GatewayPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AtmosGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'passes.gateway' => 'atmos',
            'passes.price_som' => 1000,
            'atmos.base_url' => 'https://atmos.test',
            'atmos.consumer_key' => 'key',
            'atmos.consumer_secret' => 'secret',
            'atmos.store_id' => 77,
            'atmos.api_key' => 'apikey',
            'atmos.sign_algo' => 'md5',
            'atmos.callback_ips' => [],
        ]);
        Cache::flush();
    }

    private function fakeAtmos(bool $paid = false, bool $final = false): void
    {
        // Re-faking must replace earlier stubs (the first matching stub wins).
        Http::swap(new Factory);
        Http::fake([
            'atmos.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'atmos.test/checkout/invoice/create' => Http::response([
                'store_id' => 77, 'payment_id' => 555, 'token' => 't',
                'url' => 'https://checkout.atmos.test/ru/invoice?id=t',
                'status' => ['code' => 'OK', 'description' => 'Success'],
            ]),
            'atmos.test/checkout/invoice/get' => Http::response([
                'status' => ['code' => '0', 'description' => 'Success'],
                'state' => $paid ? 'SUCCESS' : 'PENDING', 'amount' => 100000,
                'success' => $paid, 'final' => $final,
            ]),
        ]);
    }

    private function agent(): User
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->approved()->create();

        return $user;
    }

    private function buy(User $agent): GatewayPayment
    {
        app('auth')->forgetGuards();
        $this->withToken($agent->createToken('t')->plainTextToken)
            ->postJson('/api/v1/agent/pass/purchase')
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.atmos.test/ru/invoice?id=t');

        return GatewayPayment::query()->firstOrFail();
    }

    /** @return array<string, string> */
    private function billing(GatewayPayment $p, string $key = 'apikey', ?int $amount = null): array
    {
        $amount ??= $p->amount_tiyin;

        return [
            'store_id' => '77', 'transaction_id' => '9001', 'transaction_time' => 'x',
            'amount' => (string) $amount, 'account' => $p->reference,
            'sign' => md5('77'.'9001'.$p->reference.$amount.$key),
        ];
    }

    public function test_purchase_creates_atmos_invoice_with_reference_as_account(): void
    {
        $this->fakeAtmos();
        $payment = $this->buy($this->agent());

        $this->assertSame('atmos', $payment->gateway);
        $this->assertSame('555', $payment->gateway_ref);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkout/invoice/create')
            && $r['account'] === $payment->reference
            && $r['amount'] === 100000
            && $r['store_id'] === 77);
    }

    public function test_billing_callback_authorises_but_does_not_activate(): void
    {
        $this->fakeAtmos();
        $payment = $this->buy($this->agent());

        $this->postJson('/api/v1/payments/atmos/callback', $this->billing($payment))
            ->assertOk()->assertJson(['status' => 1]);

        $this->assertSame(GatewayPaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, AgentPass::query()->count());
    }

    public function test_billing_callback_rejects_bad_sign_and_wrong_amount(): void
    {
        $this->fakeAtmos();
        $payment = $this->buy($this->agent());

        $this->postJson('/api/v1/payments/atmos/callback', $this->billing($payment, 'wrong'))
            ->assertOk()->assertJson(['status' => 0]);
        $this->postJson('/api/v1/payments/atmos/callback', $this->billing($payment, 'apikey', 5))
            ->assertOk()->assertJson(['status' => 0]);
    }

    public function test_billing_callback_rejects_foreign_ip(): void
    {
        config(['atmos.callback_ips' => ['92.63.207.0/24']]);
        $this->fakeAtmos();
        $payment = $this->buy($this->agent());

        $this->postJson('/api/v1/payments/atmos/callback', $this->billing($payment))
            ->assertOk()->assertJson(['status' => 0]);
    }

    public function test_pass_activates_once_when_invoice_is_final_success(): void
    {
        $this->fakeAtmos();
        $agent = $this->agent();
        $payment = $this->buy($agent);

        $this->fakeAtmos(paid: true, final: true);
        $this->artisan('gateway:reconcile-payments')->assertSuccessful();
        $this->assertSame(0, AgentPass::query()->count(), 'too fresh to reconcile');

        $this->travel(2)->minutes();
        $this->artisan('gateway:reconcile-payments')->assertSuccessful();
        $this->artisan('gateway:reconcile-payments')->assertSuccessful();

        $this->assertSame(GatewayPaymentStatus::Success, $payment->fresh()->status);
        $this->assertSame(1, AgentPass::query()->where('user_id', $agent->id)->count());
    }

    public function test_polling_pass_endpoint_settles_a_paid_invoice(): void
    {
        $this->fakeAtmos();
        $agent = $this->agent();
        $this->buy($agent);

        $this->fakeAtmos(paid: true, final: true);
        app('auth')->forgetGuards();
        $this->withToken($agent->createToken('t2')->plainTextToken)
            ->getJson('/api/v1/agent/pass')
            ->assertOk()->assertJsonPath('data.active', true);
    }

    public function test_unpaid_invoice_expires_after_ttl(): void
    {
        $this->fakeAtmos();
        $payment = $this->buy($this->agent());

        $this->travel(45)->minutes();
        $this->artisan('gateway:reconcile-payments')->assertSuccessful();

        $this->assertSame(GatewayPaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0, AgentPass::query()->count());
    }
}
