<?php

namespace App\Services\Admin;

use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\WalletTransactionType;
use App\Models\AgentPass;
use App\Models\GatewayPayment;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderProblemResolution;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\WalletTransaction;
use App\Services\Payout\PayoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers and registers the finance team works from. The overview is
 * three separate ledgers, so a reader never adds apples to oranges:
 *
 *  - `orders`    — client money for deals (escrow): what came in, what went
 *                  back, what went out to agents and what the platform kept.
 *                  Every figure is a flow inside the selected period.
 *  - `positions` — what is owed right now (receivables, payouts due, agent
 *                  balances). These are balances as of today and ignore the
 *                  period on purpose.
 *  - `fees`      — the platform's own sales to agents (per-order fees paid
 *                  from the prepaid balance) and the card money that feeds it.
 *                  Period flows again.
 *
 * All money is kept in tiyin internally and reported alongside in som.
 */
class FinanceService
{
    /**
     * The business runs on Tashkent time: a "day" in the period picker and the
     * timestamps in the registers are local, while the database stays in UTC.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    /** Payments whose money actually arrived (a later refund keeps `paid_at`). */
    private const COLLECTED_STATUSES = [PaymentStatus::Success->value, PaymentStatus::Revert->value];

    public function __construct(
        private readonly PayoutService $payouts,
    ) {}

    /**
     * Period boundaries for the given local calendar dates (default: this
     * month so far), converted to the app timezone for querying.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function period(?string $from, ?string $to): array
    {
        $start = $from
            ? Carbon::parse($from, self::TIMEZONE)->startOfDay()
            : now(self::TIMEZONE)->startOfMonth();

        $end = $to
            ? Carbon::parse($to, self::TIMEZONE)->endOfDay()
            : now(self::TIMEZONE)->endOfDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * Period totals for the finance overview.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        return [
            'period' => [
                'from' => $from->copy()->setTimezone(self::TIMEZONE)->toIso8601String(),
                'to' => $to->copy()->setTimezone(self::TIMEZONE)->toIso8601String(),
            ],
            'orders' => $this->orders($from, $to),
            'positions' => $this->positions(),
            'fees' => $this->fees($from, $to),
        ];
    }

    /**
     * Client money for deals, as period flows.
     *
     * @return array<string, mixed>
     */
    private function orders(Carbon $from, Carbon $to): array
    {
        // Query builder, not Eloquent: the grouped rows are aggregates, and a
        // cast enum column would blow up on the way out. A refunded payment
        // still came in: it is reported gross here and subtracted once under
        // `refunded`, otherwise net would count the refund twice.
        $collectedByMethod = DB::table('payments')
            ->whereIn('status', self::COLLECTED_STATUSES)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total, COUNT(*) as count')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'method' => (string) $row->method,
                'total' => (int) $row->total,
                'total_som' => (int) $row->total / 100,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $collected = $this->counted(
            array_sum(array_column($collectedByMethod, 'total')),
            array_sum(array_column($collectedByMethod, 'count')),
        );

        $refunds = $this->refunds($from, $to);
        $refunded = $this->counted(
            array_sum(array_column($refunds, 'tiyin')),
            array_sum(array_column($refunds, 'count')),
        );

        $paidOut = Payout::query()
            ->where('status', PayoutStatus::Paid)
            ->whereBetween('paid_at', [$from, $to]);

        $commission = $this->commission($from, $to);

        return [
            'collected' => $collected,
            'collected_by_method' => $collectedByMethod,
            'refunded' => $refunded,
            'refunded_by_source' => $refunds,
            'net_collected' => $this->money($collected['tiyin'] - $refunded['tiyin']),
            'paid_to_agents' => $this->counted((int) (clone $paidOut)->sum('amount'), (clone $paidOut)->count()),
            'commission' => $commission,
            'deals_completed' => Order::query()
                ->where('status', OrderStatus::Completed)
                ->whereBetween('completed_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * Every way client money goes back, by the date it was handed back. A
     * reverted order payment is the common case; an applied addendum that cut
     * the price and a problem-order settlement are returned by hand and live
     * on their own tables, so they would otherwise never show up here.
     *
     * @return array<string, array{tiyin: int, som: float, count: int}>
     */
    private function refunds(Carbon $from, Carbon $to): array
    {
        $payments = Payment::query()
            ->where('status', PaymentStatus::Revert)
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$from, $to]);

        $amendments = OrderAmendment::query()
            ->where('refund_state', OrderAmendment::REFUND_REFUNDED)
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$from, $to]);

        $problems = OrderProblemResolution::query()
            ->where('resolution', OrderProblemResolution::REFUNDED)
            ->whereBetween('resolved_at', [$from, $to]);

        return [
            'payments' => $this->counted((int) (clone $payments)->sum('amount'), (clone $payments)->count()),
            // Addendum refunds are stored in som.
            'amendments' => $this->counted(
                (int) round(((float) (clone $amendments)->sum('refund_amount')) * 100),
                (clone $amendments)->count(),
            ),
            'problems' => $this->counted((int) (clone $problems)->sum('refund_amount'), (clone $problems)->count()),
        ];
    }

    /**
     * Commission the platform earned on deals that closed in the period. It is
     * withheld from the agent's final payout rather than invoiced, so it is
     * accrued from the deal price at the same rate the payout ledger uses —
     * and only on deals the client actually paid for: a deal closed with no
     * money collected (gateway off, or still owed) earns nothing.
     *
     * @return array{tiyin: int, som: float, deals: int}
     */
    private function commission(Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->where('status', OrderStatus::Completed)
            ->where('payment_state', OrderPaymentState::Paid)
            ->whereBetween('completed_at', [$from, $to])
            ->with('acceptedOffer')
            ->get();

        $total = 0;
        $deals = 0;

        foreach ($orders as $order) {
            $due = $order->dueTiyin();

            if ($due <= 0) {
                continue; // Tezkor deals carry no price and no money
            }

            $total += $due - $this->payouts->agentNet($due);
            $deals++;
        }

        return $this->money($total) + ['deals' => $deals];
    }

    /**
     * What is owed right now. Balances, not flows: the period does not apply.
     *
     * @return array<string, mixed>
     */
    private function positions(): array
    {
        // A deal may close before the client settles (the final payout is then
        // held), so completed orders are receivables too — not just active ones.
        $unpaid = Order::query()
            ->where('payment_state', OrderPaymentState::Unpaid)
            ->whereIn('status', [OrderStatus::InProgress, OrderStatus::WorkSubmitted, OrderStatus::Completed])
            ->with(['acceptedOffer', 'payments', 'amendments'])
            ->get()
            ->map(fn (Order $order): int => $order->outstandingTiyin())
            ->filter(fn (int $tiyin): bool => $tiyin > 0);

        $pending = Payout::query()->where('status', PayoutStatus::Pending);
        $releasable = Payout::query()->releasable();

        $balances = WalletTransaction::query()
            ->selectRaw('user_id, COALESCE(SUM(amount_tiyin), 0) as balance')
            ->groupBy('user_id')
            ->havingRaw('COALESCE(SUM(amount_tiyin), 0) <> 0')
            ->get();

        return [
            'as_of' => now(self::TIMEZONE)->toIso8601String(),
            'receivables' => $this->money((int) $unpaid->sum()) + ['orders' => $unpaid->count()],
            'pending_payouts' => $this->counted((int) (clone $pending)->sum('amount'), (clone $pending)->count()),
            // The slice of pending a manager can transfer today: cooling-off
            // window closed, client paid, no open problem report.
            'releasable_payouts' => $this->counted((int) (clone $releasable)->sum('amount'), (clone $releasable)->count()),
            'agent_balances' => $this->money((int) $balances->sum('balance')) + ['agents' => $balances->count()],
        ];
    }

    /**
     * The platform's own sales to agents, apart from the order escrow above:
     * per-order fees (on otklik, or on taking a Tezkor job) charged from the
     * prepaid balance. A card top-up is only prepaid — it sits on the agent's
     * balance until a fee spends it — so it is reported as cash in, not revenue.
     *
     * @return array<string, mixed>
     */
    private function fees(Carbon $from, Carbon $to): array
    {
        // Fees are stored as wallet debits (negative amounts).
        $ledger = fn (WalletTransactionType $type) => WalletTransaction::query()
            ->where('type', $type)
            ->whereBetween('created_at', [$from, $to]);

        $debit = fn (WalletTransactionType $type): array => $this->counted(
            -(int) $ledger($type)->sum('amount_tiyin'),
            $ledger($type)->count(),
        );

        $responseFees = $debit(WalletTransactionType::ResponseFee);
        $takeFees = $debit(WalletTransactionType::TakeFee);

        // The daily Propusk is retired (2026-10-14); old paid passes still count
        // as revenue of their day. Admin grants are free (price 0) and carry no money.
        $passes = AgentPass::query()
            ->where('price_tiyin', '>', 0)
            ->whereBetween('created_at', [$from, $to]);
        $passSales = $this->counted((int) (clone $passes)->sum('price_tiyin'), (clone $passes)->count());

        // Cash that arrived by card, gross: a payment reversed later still came
        // in on its day and is subtracted once under `card_refunds`.
        $cardIn = GatewayPayment::query()
            ->whereIn('status', [GatewayPaymentStatus::Success, GatewayPaymentStatus::Refunded])
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to]);

        // A card reversal takes the money back out of the wallet on the day it
        // happens — that debit is the refund's only dated record.
        $cardRefunds = $debit(WalletTransactionType::Refund);

        $adjustments = $ledger(WalletTransactionType::Adjustment);

        return [
            'revenue' => $this->money($responseFees['tiyin'] + $takeFees['tiyin'] + $passSales['tiyin']),
            'response_fees' => $responseFees,
            'take_fees' => $takeFees,
            'pass_sales' => $passSales,
            'card_collected' => $this->counted((int) (clone $cardIn)->sum('amount_tiyin'), (clone $cardIn)->count()),
            'card_refunds' => $cardRefunds,
            // Manual balance corrections, signed (a grant is +, a write-off is −).
            'adjustments' => $this->counted((int) (clone $adjustments)->sum('amount_tiyin'), (clone $adjustments)->count()),
        ];
    }

    /**
     * Rows of the Propusk card-payments register (pass purchases and balance
     * top-ups paid through the gateway).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, string>>
     */
    public function gatewayPaymentsRegister(array $filters): array
    {
        $query = GatewayPayment::query()
            ->with('user.profile')
            ->whereIn('status', [GatewayPaymentStatus::Success, GatewayPaymentStatus::Refunded])
            ->whereNotNull('paid_at')
            ->orderBy('paid_at');

        $this->applyPeriod($query, $filters, 'paid_at');

        return $query->get()->map(fn (GatewayPayment $payment): array => [
            'id' => (string) $payment->id,
            'paid_at' => $this->local($payment->paid_at, 'd.m.Y H:i'),
            'agent' => (string) ($payment->user?->profile?->company_name
                ?? trim(($payment->user?->first_name ?? '').' '.($payment->user?->last_name ?? ''))),
            'phone' => (string) ($payment->user?->phone ?? ''),
            'purpose' => $payment->purpose->value,
            'gateway' => (string) $payment->gateway,
            'amount_som' => $this->som($payment->amount_tiyin),
            'card' => (string) (is_array($payment->meta) ? ($payment->meta['card_mask'] ?? '') : ''),
            'reference' => (string) ($payment->gateway_ref ?? $payment->reference),
            'status' => $payment->status->value,
            'refunded_at' => (string) (is_array($payment->meta) && isset($payment->meta['refund']['at'])
                ? $this->local(Carbon::parse($payment->meta['refund']['at']), 'd.m.Y H:i')
                : ''),
        ])->values()->all();
    }

    /**
     * Rows of the incoming-payments register. By default only money that
     * actually arrived (settled, and later reverted) — the bookkeeping has no
     * use for abandoned invoices; `status=all` lists every attempt.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, string>>
     */
    public function paymentsRegister(array $filters): array
    {
        $query = Payment::query()
            ->with(['payer', 'payable'])
            ->where('purpose', PaymentPurpose::Order)
            ->orderBy('id');

        $status = (string) ($filters['status'] ?? '');

        if ($status === '') {
            $query->whereIn('status', self::COLLECTED_STATUSES);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        // Settled money is dated by when it arrived; anything else only has a
        // creation date.
        $settledOnly = $status === '' || in_array($status, self::COLLECTED_STATUSES, true);
        $this->applyPeriod($query, $filters, $settledOnly ? 'paid_at' : 'created_at');

        return $query->get()->map(fn (Payment $payment): array => [
            'id' => (string) $payment->id,
            'created_at' => $this->local($payment->created_at, 'd.m.Y H:i'),
            'paid_at' => $this->local($payment->paid_at, 'd.m.Y H:i'),
            'order_id' => (string) $payment->payable_id,
            'order_title' => (string) ($payment->payable?->title ?? ''),
            'payer' => trim(($payment->payer?->first_name ?? '').' '.($payment->payer?->last_name ?? '')),
            'payer_phone' => (string) ($payment->payer?->phone ?? ''),
            'method' => (string) $payment->method?->value,
            'status' => $payment->status->value,
            'percent' => $payment->percent !== null ? (string) $payment->percent : '',
            'amount_som' => $this->som($payment->amount),
            'reference' => (string) ($payment->reference ?? ''),
            'refunded_at' => $this->local($payment->refunded_at, 'd.m.Y H:i'),
        ])->values()->all();
    }

    /**
     * Rows of the agent-payout register — everything a bank transfer needs.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, string>>
     */
    public function payoutsRegister(array $filters): array
    {
        $query = Payout::query()
            ->with(['agent', 'agentProfile', 'order'])
            ->orderBy('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tranche'])) {
            $query->where('tranche', $filters['tranche']);
        }

        if (! empty($filters['ready'])) {
            $query->releasable();
        }

        $this->applyPeriod($query, $filters, 'created_at');

        return $query->get()->map(fn (Payout $payout): array => [
            'id' => (string) $payout->id,
            'created_at' => $this->local($payout->created_at, 'd.m.Y'),
            'paid_at' => $this->local($payout->paid_at, 'd.m.Y'),
            'order_id' => (string) $payout->order_id,
            'tranche' => $payout->tranche->value,
            'status' => $payout->status->value,
            'agent' => (string) ($payout->agentProfile?->company_name
                ?? trim(($payout->agent?->first_name ?? '').' '.($payout->agent?->last_name ?? ''))),
            'inn' => (string) ($payout->agentProfile?->inn ?? ''),
            'bank_name' => (string) ($payout->agentProfile?->bank_name ?? ''),
            'bank_account' => (string) ($payout->agentProfile?->bank_account ?? ''),
            'mfo' => (string) ($payout->agentProfile?->mfo ?? ''),
            'amount_som' => $this->som($payout->amount),
            'reference' => (string) ($payout->reference ?? ''),
        ])->values()->all();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyPeriod($query, array $filters, string $column): void
    {
        if (! empty($filters['from'])) {
            $query->where($column, '>=', Carbon::parse((string) $filters['from'], self::TIMEZONE)->startOfDay()->utc());
        }

        if (! empty($filters['to'])) {
            $query->where($column, '<=', Carbon::parse((string) $filters['to'], self::TIMEZONE)->endOfDay()->utc());
        }
    }

    private function local(?\DateTimeInterface $at, string $format): string
    {
        return $at ? Carbon::instance($at)->setTimezone(self::TIMEZONE)->format($format) : '';
    }

    /**
     * @return array{tiyin: int, som: float}
     */
    private function money(int $tiyin): array
    {
        return ['tiyin' => $tiyin, 'som' => $tiyin / 100];
    }

    /**
     * @return array{tiyin: int, som: float, count: int}
     */
    private function counted(int $tiyin, int $count): array
    {
        return $this->money($tiyin) + ['count' => $count];
    }

    private function som(int $tiyin): string
    {
        return number_format($tiyin / 100, 2, '.', '');
    }
}
