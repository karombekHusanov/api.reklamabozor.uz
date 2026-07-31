<?php

namespace Tests\Feature\Payment;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentEarningsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAgent(): array
    {
        $agent = User::factory()->create();

        return [$agent, ['Authorization' => 'Bearer '.$agent->createToken('t')->plainTextToken]];
    }

    public function test_earnings_returns_balance_summary_and_own_payouts(): void
    {
        [$agent, $headers] = $this->actingAgent();

        Payout::factory()->create(['agent_id' => $agent->id, 'amount' => 372_000_000]); // pending
        Payout::factory()->create(['agent_id' => $agent->id, 'amount' => 100_000_000]); // pending
        Payout::factory()->paid()->create(['agent_id' => $agent->id, 'amount' => 50_000_000]);

        // Another agent's payout must not leak in.
        Payout::factory()->create(['amount' => 999_000_000]);

        $this->getJson('/api/v1/agent/payouts', $headers)
            ->assertOk()
            ->assertJsonPath('data.balance.available', 472_000_000)
            ->assertJsonPath('data.balance.available_som', 4_720_000)
            ->assertJsonPath('data.balance.paid', 50_000_000)
            ->assertJsonPath('data.balance.total', 522_000_000)
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonCount(3, 'data.items');
    }

    public function test_earnings_requires_auth(): void
    {
        $this->getJson('/api/v1/agent/payouts')->assertUnauthorized();
    }
}
