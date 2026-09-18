<?php

namespace App\Services\Order;

use App\Enums\OrderProblemReason;
use App\Enums\OrderProblemState;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderProblemEvent;
use App\Models\OrderProblemResolution;
use App\Models\User;
use App\Services\Payout\PayoutService;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Problem orders" queue: two risk scenarios funnel here —
 *  - a quality dispute whose correction window ran out without the order
 *    reaching `completed` (system-flagged, see {@see flagFromDispute()});
 *  - an agent who took the advance but never started the work
 *    (client-flagged, see {@see OrderService::reportNoStart()}).
 *
 * An admin then either records a manual refund — the amount is judged by a
 * human and often partial, so this is an audit entry rather than a ledger
 * transaction (the money itself moves outside the platform, the same way
 * {@see PayoutService::release()} records a bank
 * transfer it did not itself send) — or dismisses the report, and the deal
 * continues normally.
 */
class OrderProblemService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly AdminNotifier $admin,
    ) {}

    /**
     * Scheduled-sweep target: a dispute's correction window ran out without
     * the order reaching `completed`. Idempotent.
     */
    public function flagFromDispute(Order $order): void
    {
        if ($order->problem_state !== OrderProblemState::None) {
            return;
        }

        if ($order->status === OrderStatus::Completed) {
            return;
        }

        if ($order->correction_deadline_at === null || $order->correction_deadline_at->isFuture()) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $order->update([
                'problem_state' => OrderProblemState::Flagged,
                'problem_reason' => OrderProblemReason::QualityUnresolved,
                'problem_flagged_at' => now(),
            ]);

            $this->logEvent($order, null, 'system', OrderProblemEvent::FLAGGED, [
                'reason' => OrderProblemReason::QualityUnresolved->value,
                'correction_deadline_at' => $order->correction_deadline_at?->toIso8601String(),
            ]);
        });

        try {
            $this->admin->orderProblemFlagged($order->fresh(), OrderProblemReason::QualityUnresolved);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Manager records a manual refund. No partial-vs-full validation here —
     * unlike the amendment refund path, this amount is a human judgement call
     * (the loss from a quality issue is rarely the full deal price) and is
     * intentionally not tied to the payment ledger.
     */
    public function resolveWithRefund(
        Order $order,
        User $admin,
        int $amountTiyin,
        string $method,
        ?string $reference,
        ?string $note,
    ): OrderProblemResolution {
        $this->assertFlagged($order);

        $resolution = DB::transaction(function () use ($order, $admin, $amountTiyin, $method, $reference, $note): OrderProblemResolution {
            $resolution = OrderProblemResolution::create([
                'order_id' => $order->id,
                'resolution' => OrderProblemResolution::REFUNDED,
                'refund_amount' => $amountTiyin,
                'refund_method' => $method,
                'reference' => $reference,
                'note' => $note,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            $order->update([
                'problem_state' => OrderProblemState::Resolved,
                'problem_resolved_at' => now(),
            ]);

            $this->logEvent($order, $admin, 'admin', OrderProblemEvent::REFUNDED, [
                'amount' => $amountTiyin,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
            ]);

            return $resolution;
        });

        try {
            $this->notifier->notifyOrderProblemResolved($order->fresh(), refunded: true);
        } catch (\Throwable $e) {
            report($e);
        }

        return $resolution;
    }

    /**
     * The report is judged unfounded — the deal continues as a normal
     * in-progress order. Every problem-state trace is cleared so the client
     * (or agent) can report again later if a new issue comes up.
     */
    public function dismiss(Order $order, User $admin, ?string $note = null): void
    {
        $this->assertFlagged($order);

        DB::transaction(function () use ($order, $admin, $note): void {
            OrderProblemResolution::create([
                'order_id' => $order->id,
                'resolution' => OrderProblemResolution::DISMISSED,
                'note' => $note,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            $order->update([
                'problem_state' => OrderProblemState::None,
                'problem_reason' => null,
                'problem_flagged_at' => null,
                'correction_deadline_at' => null,
                'problem_resolved_at' => null,
            ]);

            $this->logEvent($order, $admin, 'admin', OrderProblemEvent::DISMISSED, [
                'note' => $note,
            ]);
        });

        try {
            $this->notifier->notifyOrderProblemResolved($order->fresh(), refunded: false);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Write one entry into the problem order's audit trail. Public: the
     * client-initiated "agent never started" report
     * ({@see OrderService::reportNoStart()}) logs through
     * here too, so every path into the queue shares one audit writer.
     *
     * @param  array<string, mixed>  $payload
     */
    public function logEvent(Order $order, ?User $actor, string $actorRole, string $type, array $payload = []): void
    {
        OrderProblemEvent::create([
            'order_id' => $order->id,
            'actor_id' => $actor?->id,
            'actor_role' => $actorRole,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    private function assertFlagged(Order $order): void
    {
        if ($order->problem_state !== OrderProblemState::Flagged) {
            throw ValidationException::withMessages([
                'order' => ['This order has no open problem report to resolve.'],
            ]);
        }
    }
}
