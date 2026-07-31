<?php

namespace Tests\Feature\Payment;

use App\Enums\PayoutStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Payout;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.multicard.enabled' => true,
            'services.multicard.base_url' => 'https://gw.test',
            'services.multicard.store_id' => 6,
            'services.multicard.callback_url' => 'https://api.test/cb',
        ]);

        Http::fake([
            'gw.test/auth' => Http::response(['token' => 'tok', 'expiry' => now()->addHour()->format('Y-m-d H:i:s')]),
            'gw.test/payment/card/bind/*' => Http::response(['success' => true, 'data' => [
                'status' => 'active', 'card_token' => 'CARD_TOKEN', 'card_pan' => '860060******1111', 'ps' => 'uzcard',
            ]]),
            'gw.test/payment/card/bind' => Http::response(['success' => true, 'data' => [
                'session_id' => 'sess-1', 'form_url' => 'https://checkout.test/card/sess-1',
            ]]),
            'gw.test/payment/credit/*' => Http::response(['success' => true, 'data' => ['status' => 'success']]),
            'gw.test/payment/card/*' => Http::response(['success' => true, 'data' => []]),
        ]);
        // The credit POST outcome is per-test (Http::fake merges, first match
        // wins) so each scenario declares it: OTP-less success vs OTP fallback.
    }

    private function fakeCreditPost(string $status): void
    {
        Http::fake([
            'gw.test/payment/credit' => Http::response(['success' => true, 'data' => ['uuid' => 'credit-uuid', 'status' => $status]]),
        ]);
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function actingAgent(): array
    {
        $agent = User::factory()->create(['phone' => '+998901234567']);

        return [$agent, ['Authorization' => 'Bearer '.$agent->createToken('t')->plainTextToken]];
    }

    public function test_otp_less_card_withdrawal_flow(): void
    {
        $this->fakeCreditPost('success'); // OTP-less credit settles in one shot
        [$agent, $headers] = $this->actingAgent();
        Payout::factory()->create(['agent_id' => $agent->id, 'amount' => 372_000_000]);
        Payout::factory()->create(['agent_id' => $agent->id, 'amount' => 100_000_000]);

        // 1. Start — reserves payouts, opens hosted form.
        $start = $this->postJson('/api/v1/agent/withdrawals', [], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'card_pending')
            ->assertJsonPath('data.amount', 472_000_000)
            ->assertJsonPath('data.form_url', 'https://checkout.test/card/sess-1');

        $id = $start->json('data.id');
        $this->assertSame(2, $agent->payouts()->where('status', PayoutStatus::Processing->value)->count());

        // 2. Poll — card is bound; OTP-less credit settles in one shot. No
        //    second code is ever requested from the user in the app.
        $this->getJson("/api/v1/agent/withdrawals/{$id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.card_pan', '860060******1111');

        $this->assertSame(2, $agent->payouts()->where('status', PayoutStatus::Paid->value)->count());
        $this->assertNull(Withdrawal::find($id)->card_token); // token dropped
    }

    public function test_otp_fallback_when_terminal_requires_confirmation(): void
    {
        // Terminal without OTP-less credit: the gateway returns a draft that
        // still needs an OTP — we fall back to the confirm step.
        $this->fakeCreditPost('draft');

        [$agent, $headers] = $this->actingAgent();
        Payout::factory()->create(['agent_id' => $agent->id, 'amount' => 200_000_000]);

        $id = $this->postJson('/api/v1/agent/withdrawals', [], $headers)->json('data.id');

        $this->getJson("/api/v1/agent/withdrawals/{$id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'otp_required');

        $this->postJson("/api/v1/agent/withdrawals/{$id}/confirm", ['otp' => '112233'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertSame(1, $agent->payouts()->where('status', PayoutStatus::Paid->value)->count());
    }

    public function test_start_fails_with_no_available_balance(): void
    {
        [, $headers] = $this->actingAgent();

        $this->postJson('/api/v1/agent/withdrawals', [], $headers)->assertStatus(422);
    }

    public function test_cancel_returns_funds_to_available(): void
    {
        [$agent, $headers] = $this->actingAgent();
        Payout::factory()->create(['agent_id' => $agent->id, 'amount' => 200_000_000]);

        $id = $this->postJson('/api/v1/agent/withdrawals', [], $headers)->json('data.id');
        $this->postJson("/api/v1/agent/withdrawals/{$id}/cancel", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(1, $agent->payouts()->where('status', PayoutStatus::Pending->value)->count());
    }

    public function test_cannot_touch_another_agents_withdrawal(): void
    {
        // Build the withdrawal directly so the only authenticated request in
        // this test is the outsider's (avoids the Sanctum guard memoizing the
        // first request's user across requests in a single test).
        $owner = User::factory()->create(['phone' => '+998901234567']);
        $withdrawal = $owner->withdrawals()->create([
            'method' => 'card', 'amount' => 200_000_000, 'currency' => 'UZS',
            'status' => WithdrawalStatus::CardPending, 'session_id' => 'sess-x',
        ]);

        [, $other] = $this->actingAgent();
        $this->getJson("/api/v1/agent/withdrawals/{$withdrawal->id}", $other)->assertNotFound();
    }

    public function test_withdrawals_disabled_when_gateway_off(): void
    {
        config(['services.multicard.enabled' => false]);
        [, $headers] = $this->actingAgent();

        $this->postJson('/api/v1/agent/withdrawals', [], $headers)->assertStatus(422);
    }
}
