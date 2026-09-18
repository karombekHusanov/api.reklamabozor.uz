<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderProblemReason;
use App\Enums\OrderProblemState;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderProblemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Problem orders" queue: a quality dispute whose correction window ran out
 * (system-flagged sweep), and an agent who took the advance but never
 * started (client-flagged report). A manager resolves each with a manual
 * refund or a dismissal.
 */
class OrderProblemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Order} [client, agent, order]
     */
    private function activeDeal(): array
    {
        $client = User::factory()->create();
        $agent = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->create(['status' => OfferStatus::Accepted]);

        return [$client, $agent, $order->fresh()];
    }

    /**
     * A paid, active deal eligible (or not yet eligible) for a no-start report.
     *
     * @return array{0: User, 1: User, 2: Order}
     */
    private function paidActiveDeal(int $activatedDaysAgo): array
    {
        [$client, $agent, $order] = $this->activeDeal();

        $order->update([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => now()->subDays($activatedDaysAgo),
            'activated_at' => now()->subDays($activatedDaysAgo),
        ]);

        return [$client, $agent, $order->fresh()];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function admin(): array
    {
        $user = User::factory()->create(['role' => Role::Admin]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // --- Dispute sets a correction deadline --------------------------------

    public function test_dispute_sets_a_correction_deadline(): void
    {
        config(['orders.quality_correction_window_days' => 3]);
        Http::fake();
        [$client, , $order] = $this->activeDeal();
        $order->update(['status' => OrderStatus::WorkSubmitted, 'work_submitted_at' => now()]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], $this->auth($token))
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->correction_deadline_at);
        $this->assertTrue($fresh->correction_deadline_at->between(
            now()->addDays(3)->subMinute(),
            now()->addDays(3)->addMinute(),
        ));
    }

    public function test_a_repeat_dispute_recalculates_the_deadline(): void
    {
        config(['orders.quality_correction_window_days' => 3]);
        Http::fake();
        [$client, , $order] = $this->activeDeal();
        $order->update([
            'status' => OrderStatus::WorkSubmitted,
            'work_submitted_at' => now(),
            'correction_deadline_at' => now()->addDay(), // stale, from an earlier dispute
        ]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], $this->auth($token))->assertOk();

        $fresh = $order->fresh();
        $this->assertTrue($fresh->correction_deadline_at->isAfter(now()->addDays(2)));
    }

    // --- Sweep ---------------------------------------------------------------

    public function test_sweep_flags_an_order_whose_correction_window_elapsed(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        [, , $order] = $this->activeDeal();
        $order->update(['correction_deadline_at' => now()->subDay()]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame(OrderProblemState::Flagged, $fresh->problem_state);
        $this->assertNotNull($fresh->problem_flagged_at);
        $this->assertSame(1, $order->problemEvents()->count());

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777'
            && str_contains($request['text'] ?? '', 'Muammoli'));
    }

    public function test_sweep_does_not_flag_an_order_still_inside_the_window(): void
    {
        [, , $order] = $this->activeDeal();
        $order->update(['correction_deadline_at' => now()->addDay()]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $this->assertSame(OrderProblemState::None, $order->fresh()->problem_state);
    }

    public function test_sweep_does_not_flag_a_completed_order(): void
    {
        [, , $order] = $this->activeDeal();
        $order->update([
            'status' => OrderStatus::Completed,
            'correction_deadline_at' => now()->subDay(),
        ]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $this->assertSame(OrderProblemState::None, $order->fresh()->problem_state);
    }

    public function test_sweep_is_idempotent(): void
    {
        [, , $order] = $this->activeDeal();
        $order->update(['correction_deadline_at' => now()->subDay()]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();
        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $this->assertSame(1, $order->problemEvents()->count());
    }

    // --- Client "agent never started" report ----------------------------------

    public function test_report_no_start_is_rejected_before_the_grace_period(): void
    {
        config(['orders.no_start_report_min_days' => 3]);
        [$client, , $order] = $this->paidActiveDeal(activatedDaysAgo: 1);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))
            ->assertUnprocessable();

        $this->assertSame(OrderProblemState::None, $order->fresh()->problem_state);
    }

    public function test_report_no_start_succeeds_after_the_grace_period(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        config(['orders.no_start_report_min_days' => 3]);
        [$client, , $order] = $this->paidActiveDeal(activatedDaysAgo: 4);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.problem_state', 'flagged')
            ->assertJsonPath('data.problem_reason', 'agent_no_start');

        $fresh = $order->fresh();
        $this->assertSame(OrderProblemState::Flagged, $fresh->problem_state);
        $this->assertNotNull($fresh->problem_flagged_at);

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777');
    }

    public function test_report_no_start_twice_is_rejected(): void
    {
        Http::fake();
        config(['orders.no_start_report_min_days' => 3]);
        [$client, , $order] = $this->paidActiveDeal(activatedDaysAgo: 4);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))
            ->assertUnprocessable();
    }

    public function test_report_no_start_rejected_once_work_is_submitted(): void
    {
        config(['orders.no_start_report_min_days' => 3]);
        [$client, , $order] = $this->paidActiveDeal(activatedDaysAgo: 4);
        $order->update(['status' => OrderStatus::WorkSubmitted, 'work_submitted_at' => now()]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))
            ->assertUnprocessable();
    }

    public function test_only_the_owning_client_can_report_no_start(): void
    {
        config(['orders.no_start_report_min_days' => 3]);
        [, , $order] = $this->paidActiveDeal(activatedDaysAgo: 4);
        $stranger = User::factory()->create();
        $token = $stranger->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))
            ->assertNotFound();
    }

    public function test_reporting_no_start_notifies_the_agent(): void
    {
        Http::fake();
        config(['orders.no_start_report_min_days' => 3]);
        [$client, $agent, $order] = $this->paidActiveDeal(activatedDaysAgo: 4);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/report-no-start", [], $this->auth($token))->assertOk();

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === (int) $agent->telegram_id
            && str_contains($request['text'] ?? '', 'shikoyat qildi'));
    }

    // --- Admin resolution ------------------------------------------------------

    public function test_refund_notifies_both_the_client_and_the_agent(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->activeDeal();
        $order->update(['problem_state' => OrderProblemState::Flagged, 'problem_flagged_at' => now()]);
        [, $token] = $this->admin();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/refund", [
            'amount' => 500_000_00,
            'method' => 'bank_transfer',
        ], $this->auth($token))->assertOk();

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === (int) $client->telegram_id
            && str_contains($request['text'] ?? '', 'qaytarish rasmiylashtirildi'));
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === (int) $agent->telegram_id
            && str_contains($request['text'] ?? '', 'qaytarish rasmiylashtirildi'));
    }

    public function test_dismissal_notifies_both_the_client_and_the_agent(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->activeDeal();
        $order->update(['problem_state' => OrderProblemState::Flagged, 'problem_flagged_at' => now()]);
        [, $token] = $this->admin();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/dismiss", [], $this->auth($token))
            ->assertOk();

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === (int) $client->telegram_id);
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === (int) $agent->telegram_id
            && str_contains($request['text'] ?? '', 'asossiz'));
    }

    public function test_admin_can_record_a_refund_for_a_flagged_order(): void
    {
        Http::fake();
        [, , $order] = $this->activeDeal();
        $order->update([
            'problem_state' => OrderProblemState::Flagged,
            'problem_flagged_at' => now(),
        ]);
        [, $token] = $this->admin();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/refund", [
            'amount' => 500_000_00,
            'method' => 'bank_transfer',
            'reference' => 'PT-42',
            'note' => 'Partial — client kept half the design work.',
        ], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.problem_state', 'resolved');

        $fresh = $order->fresh();
        $this->assertSame(OrderProblemState::Resolved, $fresh->problem_state);
        $this->assertNotNull($fresh->problem_resolved_at);
        $this->assertSame(1, $order->problemResolutions()->where('resolution', 'refunded')->count());
    }

    public function test_admin_can_dismiss_a_flagged_order(): void
    {
        Http::fake();
        [, , $order] = $this->activeDeal();
        $order->update([
            'problem_state' => OrderProblemState::Flagged,
            'problem_reason' => 'agent_no_start',
            'problem_flagged_at' => now(),
            'correction_deadline_at' => now()->addDay(),
        ]);
        [, $token] = $this->admin();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/dismiss", [
            'note' => 'Agent showed proof of an earlier site visit.',
        ], $this->auth($token))
            ->assertOk()
            ->assertJsonPath('data.problem_state', 'none');

        $fresh = $order->fresh();
        $this->assertSame(OrderProblemState::None, $fresh->problem_state);
        $this->assertNull($fresh->problem_reason);
        $this->assertNull($fresh->problem_flagged_at);
        $this->assertNull($fresh->correction_deadline_at);
        $this->assertSame(OrderStatus::InProgress, $fresh->status);
        $this->assertSame(1, $order->problemResolutions()->where('resolution', 'dismissed')->count());
    }

    public function test_resolution_endpoints_require_the_order_to_be_flagged(): void
    {
        [, , $order] = $this->activeDeal(); // problem_state=none
        [, $token] = $this->admin();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/refund", [
            'amount' => 100_000,
            'method' => 'cash',
        ], $this->auth($token))->assertUnprocessable();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/dismiss", [], $this->auth($token))
            ->assertUnprocessable();
    }

    public function test_a_resolved_order_cannot_be_resolved_again(): void
    {
        Http::fake();
        [, , $order] = $this->activeDeal();
        $order->update(['problem_state' => OrderProblemState::Flagged, 'problem_flagged_at' => now()]);
        [$admin, $token] = $this->admin();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/refund", [
            'amount' => 100_000,
            'method' => 'cash',
        ], $this->auth($token))->assertOk();

        // Now resolved — a second refund or a dismiss is rejected.
        $this->postJson("/api/v1/admin/order-problems/{$order->id}/refund", [
            'amount' => 50_000,
            'method' => 'cash',
        ], $this->auth($token))->assertUnprocessable();

        $this->postJson("/api/v1/admin/order-problems/{$order->id}/dismiss", [], $this->auth($token))
            ->assertUnprocessable();
    }

    public function test_order_problems_admin_endpoints_require_admin_role(): void
    {
        [, , $order] = $this->activeDeal();
        $order->update(['problem_state' => OrderProblemState::Flagged, 'problem_flagged_at' => now()]);
        $client = User::factory()->create(['role' => Role::Client]);
        $token = $client->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/order-problems', $this->auth($token))->assertForbidden();
        $this->getJson("/api/v1/admin/order-problems/{$order->id}", $this->auth($token))->assertForbidden();
        $this->postJson("/api/v1/admin/order-problems/{$order->id}/dismiss", [], $this->auth($token))
            ->assertForbidden();
    }

    public function test_admin_queue_lists_flagged_orders_by_default(): void
    {
        [, , $flagged] = $this->activeDeal();
        $flagged->update(['problem_state' => OrderProblemState::Flagged, 'problem_flagged_at' => now()]);

        [, , $untouched] = $this->activeDeal();

        [, $token] = $this->admin();

        $this->getJson('/api/v1/admin/order-problems', $this->auth($token))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $flagged->id);
    }

    // --- Reminder before the correction deadline ------------------------------

    public function test_sweep_reminds_the_agent_a_day_before_the_deadline(): void
    {
        Http::fake();
        [, $agent, $order] = $this->activeDeal();
        $order->update(['correction_deadline_at' => now()->addHours(12)]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $this->assertNotNull($order->fresh()->correction_reminder_sent_at);
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === (int) $agent->telegram_id
            && str_contains($request['text'] ?? '', 'tugamoqda'));
    }

    public function test_sweep_does_not_remind_outside_the_24_hour_window(): void
    {
        Http::fake();
        [, , $order] = $this->activeDeal();
        $order->update(['correction_deadline_at' => now()->addDays(2)]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $this->assertNull($order->fresh()->correction_reminder_sent_at);
    }

    public function test_sweep_reminds_only_once(): void
    {
        Http::fake();
        [, , $order] = $this->activeDeal();
        $order->update(['correction_deadline_at' => now()->addHours(12)]);

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();
        $sentAt = $order->fresh()->correction_reminder_sent_at;

        $this->artisan('orders:sweep-quality-disputes')->assertSuccessful();

        $this->assertTrue($sentAt->equalTo($order->fresh()->correction_reminder_sent_at));
    }

    public function test_a_repeat_dispute_resets_the_reminder_flag(): void
    {
        config(['orders.quality_correction_window_days' => 3]);
        Http::fake();
        [$client, , $order] = $this->activeDeal();
        $order->update([
            'status' => OrderStatus::WorkSubmitted,
            'work_submitted_at' => now(),
            'correction_deadline_at' => now()->addDay(),
            'correction_reminder_sent_at' => now()->subHour(), // from an earlier cycle
        ]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], $this->auth($token))->assertOk();

        $this->assertNull($order->fresh()->correction_reminder_sent_at);
    }

    // --- Completing a flagged order still surfaces it to ops ------------------

    public function test_completing_a_flagged_order_logs_an_audit_event_and_notifies_ops(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        [$client, , $order] = $this->activeDeal();
        $order->update([
            'status' => OrderStatus::WorkSubmitted,
            'work_submitted_at' => now(),
            'problem_state' => OrderProblemState::Flagged,
            'problem_reason' => OrderProblemReason::AgentNoStart,
            'problem_flagged_at' => now(),
        ]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/complete", [], $this->auth($token))->assertOk();

        $this->assertSame(
            OrderProblemEvent::COMPLETED_WHILE_FLAGGED,
            $order->problemEvents()->latest()->first()->type,
        );

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777'
            && str_contains($request['text'] ?? '', 'yakunlandi'));
    }

    public function test_completing_an_unflagged_order_does_not_log_the_audit_event(): void
    {
        Http::fake();
        [$client, , $order] = $this->activeDeal();
        $order->update(['status' => OrderStatus::WorkSubmitted, 'work_submitted_at' => now()]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/complete", [], $this->auth($token))->assertOk();

        $this->assertSame(0, $order->problemEvents()->count());
    }
}
