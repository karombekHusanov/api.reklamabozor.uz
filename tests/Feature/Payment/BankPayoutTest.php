<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderPaymentState;
use App\Enums\OrderProblemState;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Services\Order\OrderProblemService;
use App\Services\Payout\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Agent earnings leave the platform as a bank transfer to the requisites from
 * KYC: the gateway has no account-payout API, so a manager executes it and the
 * card cash-out stays off.
 */
class BankPayoutTest extends TestCase
{
    use RefreshDatabase;

    /** A paid order whose cooling-off window has closed. */
    private function unlockedOrder(): Order
    {
        return Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now()->subHours(2),
        ]);
    }

    public function test_earnings_show_the_bank_account_the_money_goes_to(): void
    {
        $profile = AgentProfile::factory()->create(['bank_name' => 'Ipoteka Bank', 'mfo' => '00123']);
        $token = $profile->user->createToken('t')->plainTextToken;

        $this->getJson('/api/v1/agent/payouts', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.payout.channel', 'bank')
            ->assertJsonPath('data.payout.card_withdrawal_enabled', false)
            ->assertJsonPath('data.payout.bank.bank_name', 'Ipoteka Bank')
            ->assertJsonPath('data.payout.bank.complete', true);
    }

    public function test_release_is_refused_while_bank_requisites_are_incomplete(): void
    {
        $profile = AgentProfile::factory()->create(['bank_account' => null]);
        $payout = Payout::factory()->create([
            'order_id' => $this->unlockedOrder()->id,
            'agent_profile_id' => $profile->id,
            'agent_id' => $profile->user_id,
        ]);

        $this->expectException(ValidationException::class);

        app(PayoutService::class)->release($payout, User::factory()->create());
    }

    public function test_release_is_refused_while_the_order_has_an_open_problem_report(): void
    {
        $profile = AgentProfile::factory()->create();
        $order = $this->unlockedOrder();
        $order->update(['problem_state' => OrderProblemState::Flagged]);
        $payout = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_profile_id' => $profile->id,
            'agent_id' => $profile->user_id,
        ]);

        $this->expectException(ValidationException::class);

        app(PayoutService::class)->release($payout, User::factory()->create(), ['reference' => 'PO-1']);
    }

    public function test_release_works_again_once_the_problem_report_is_dismissed(): void
    {
        $profile = AgentProfile::factory()->create();
        $order = $this->unlockedOrder();
        $order->update(['problem_state' => OrderProblemState::Flagged]);
        $payout = Payout::factory()->create([
            'order_id' => $order->id,
            'agent_profile_id' => $profile->id,
            'agent_id' => $profile->user_id,
        ]);

        app(OrderProblemService::class)->dismiss($order, User::factory()->create(['role' => Role::Admin]));

        $released = app(PayoutService::class)->release($payout, User::factory()->create(), ['reference' => 'PO-2']);

        $this->assertSame(PayoutStatus::Paid, $released->status);
    }

    public function test_released_payout_records_the_bank_transfer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $token = $admin->createToken('t')->plainTextToken;

        $payout = Payout::factory()->create(['order_id' => $this->unlockedOrder()->id]);

        $this->patchJson("/api/v1/admin/payouts/{$payout->id}/release", [
            'reference' => 'PO-118',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.method', 'bank')
            ->assertJsonPath('data.reference', 'PO-118');
    }

    public function test_release_requires_the_payment_order_reference(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $token = $admin->createToken('t')->plainTextToken;

        $payout = Payout::factory()->create(['order_id' => $this->unlockedOrder()->id]);

        $this->patchJson("/api/v1/admin/payouts/{$payout->id}/release", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');

        $this->assertSame(PayoutStatus::Pending, $payout->fresh()->status);
    }

    public function test_agent_is_told_the_transfer_was_made(): void
    {
        config(['services.telegram.bot_token' => 'bot-token']);
        Http::fake();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $token = $admin->createToken('t')->plainTextToken;

        $profile = AgentProfile::factory()->create();
        $profile->user->update(['telegram_id' => 555444333]);

        $payout = Payout::factory()->create([
            'order_id' => $this->unlockedOrder()->id,
            'agent_profile_id' => $profile->id,
            'agent_id' => $profile->user_id,
            'amount' => 4_800_000,
        ]);

        $this->patchJson("/api/v1/admin/payouts/{$payout->id}/release", [
            'reference' => 'PO-2026-118',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        Http::assertSent(function ($request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_contains($request->url(), 'sendMessage')
                && (int) ($request['chat_id'] ?? 0) === 555444333
                && str_contains($text, '48 000')
                && str_contains($text, 'PO-2026-118');
        });
    }

    public function test_ready_filter_lists_only_payouts_past_the_cancel_window(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $token = $admin->createToken('t')->plainTextToken;

        $ready = Payout::factory()->create(['order_id' => $this->unlockedOrder()->id]);

        // Still inside the client's cooling-off window → frozen.
        $locked = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now(),
        ]);
        Payout::factory()->create(['order_id' => $locked->id]);

        // Cooling-off has closed, but the order has an open problem report.
        $flaggedOrder = $this->unlockedOrder();
        $flaggedOrder->update(['problem_state' => OrderProblemState::Flagged]);
        Payout::factory()->create(['order_id' => $flaggedOrder->id]);

        $response = $this->getJson('/api/v1/admin/payouts?ready=1', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $ids = array_column($response->json('data.items'), 'id');

        $this->assertSame([$ready->id], $ids);
    }

    public function test_due_payouts_are_announced_once(): void
    {
        $payout = Payout::factory()->create(['order_id' => $this->unlockedOrder()->id]);

        $this->artisan('payouts:notify-due')->assertExitCode(0);

        $this->assertNotNull($payout->fresh()->notified_at);

        // A second sweep has nothing left to announce.
        $this->artisan('payouts:notify-due')
            ->expectsOutputToContain('No payouts are waiting')
            ->assertExitCode(0);
    }

    public function test_a_flagged_order_payout_is_not_announced(): void
    {
        $order = $this->unlockedOrder();
        $order->update(['problem_state' => OrderProblemState::Flagged]);
        $payout = Payout::factory()->create(['order_id' => $order->id]);

        $this->artisan('payouts:notify-due')->assertExitCode(0);

        $this->assertNull($payout->fresh()->notified_at);
    }

    public function test_a_frozen_payout_is_not_announced(): void
    {
        $order = Order::factory()->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now(),
        ]);
        $payout = Payout::factory()->create(['order_id' => $order->id]);

        $this->artisan('payouts:notify-due')->assertExitCode(0);

        $this->assertNull($payout->fresh()->notified_at);
        $this->assertSame(PayoutStatus::Pending, $payout->fresh()->status);
    }
}
