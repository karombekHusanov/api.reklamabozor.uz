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
use App\Models\Payment;
use App\Models\Payout;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers and registers the finance team works from: what came in, what
 * went out to agents, what the platform earned, and what is still owed. All
 * money is kept in tiyin internally and reported in som.
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
            ->get()
            ->map(fn ($row): array => [
                'method' => (string) $row->method,
                'total' => (int) $row->total,
                'total_som' => (int) $row->total / 100,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $collected = array_sum(array_column($collectedByMethod, 'total'));

        $refunded = (int) Payment::query()
            ->whereNotNull('refunded_at')
            ->whereBetween('refunded_at', [$from, $to])
            ->sum('amount');

        $paidOut = (int) Payout::query()
            ->where('status', PayoutStatus::Paid)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');

        $pendingPayouts = (int) Payout::query()
            ->where('status', PayoutStatus::Pending)
            ->sum('amount');

        return [
            'period' => [
                'from' => $from->copy()->setTimezone(self::TIMEZONE)->toIso8601String(),
                'to' => $to->copy()->setTimezone(self::TIMEZONE)->toIso8601String(),
            ],
            'collected' => $this->money($collected),
            'collected_by_method' => $collectedByMethod,
            'refunded' => $this->money($refunded),
            'net_collected' => $this->money($collected - $refunded),
            'commission' => $this->money($this->commissionAccrued($from, $to)),
            'paid_to_agents' => $this->money($paidOut),
            'pending_payouts' => $this->money($pendingPayouts),
            'receivables' => $this->money($this->receivables()),
            'passes' => $this->passes($from, $to),
            'counts' => [
                'payments' => Payment::query()
                    ->whereIn('status', self::COLLECTED_STATUSES)
                    ->whereNotNull('paid_at')
                    ->whereBetween('paid_at', [$from, $to])
                    ->count(),
                'payouts' => Payout::query()
                    ->where('status', PayoutStatus::Paid)
                    ->whereBetween('paid_at', [$from, $to])
                    ->count(),
                'orders_completed' => Order::query()
                    ->where('status', OrderStatus::Completed)
                    ->whereBetween('completed_at', [$from, $to])
                    ->count(),
            ],
        ];
    }

    /**
     * Propusk money — the platform's own sales to agents, apart from the order
     * escrow above. Revenue is earned when a pass is sold (from the card or the
     * balance) or a per-response fee is charged; a top-up is only prepaid and
     * sits on the agents' balances until it is spent.
     *
     * @return array<string, mixed>
     */
    private function passes(Carbon $from, Carbon $to): array
    {
        // Admin grants are free (price 0) and carry no money.
        $sales = AgentPass::query()
            ->where('price_tiyin', '>', 0)
            ->whereBetween('created_at', [$from, $to]);

        // Fees are stored as wallet debits (negative amounts).
        $fees = WalletTransaction::query()
            ->where('type', WalletTransactionType::ResponseFee)
            ->whereBetween('created_at', [$from, $to]);

        $passSales = (int) (clone $sales)->sum('price_tiyin');
        $responseFees = -(int) (clone $fees)->sum('amount_tiyin');

        $collectedByPurpose = DB::table('gateway_payments')
            ->where('status', GatewayPaymentStatus::Success->value)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('purpose, COALESCE(SUM(amount_tiyin), 0) as total, COUNT(*) as count')
            ->groupBy('purpose')
            ->get()
            ->keyBy('purpose');

        $collected = fn (string $purpose): array => $this->money((int) ($collectedByPurpose[$purpose]->total ?? 0))
            + ['count' => (int) ($collectedByPurpose[$purpose]->count ?? 0)];

        return [
            'revenue' => $this->money($passSales + $responseFees),
            'pass_sales' => $this->money($passSales) + ['count' => (clone $sales)->count()],
            'response_fees' => $this->money($responseFees) + ['count' => (clone $fees)->count()],
            'card_collected' => $this->money((int) $collectedByPurpose->sum('total'))
                + ['count' => (int) $collectedByPurpose->sum('count')],
            'card_collected_by_purpose' => [
                'pass' => $collected('pass'),
                'topup' => $collected('topup'),
            ],
            // Owed back to agents in service, as of now (includes admin adjustments).
            'wallet_balance' => $this->money((int) WalletTransaction::query()->sum('amount_tiyin')),
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
            ->where('status', GatewayPaymentStatus::Success)
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
        ])->values()->all();
    }

    /**
     * Rows of the incoming-payments register.
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

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        $this->applyPeriod($query, $filters, 'created_at');

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
     * Commission the platform earned on deals that closed in the period. It is
     * withheld from the payout rather than invoiced, so it is accrued here from
     * the deal price and the configured rate.
     */
    private function commissionAccrued(Carbon $from, Carbon $to): int
    {
        $percent = (float) config('payments.commission_percent', 0);

        $dealTotalSom = (float) Order::query()
            ->where('orders.status', OrderStatus::Completed)
            ->whereBetween('orders.completed_at', [$from, $to])
            ->join('offers', function ($join): void {
                $join->on('offers.order_id', '=', 'orders.id')->where('offers.status', '=', 'accepted');
            })
            ->sum('offers.price');

        return (int) round($dealTotalSom * 100 * $percent / 100);
    }

    /** Money clients still owe on active deals, as of now. */
    private function receivables(): int
    {
        return Order::query()
            ->whereIn('payment_state', [OrderPaymentState::Unpaid])
            ->whereIn('status', [OrderStatus::InProgress, OrderStatus::WorkSubmitted])
            ->get()
            ->sum(fn (Order $order): int => $order->outstandingTiyin());
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

    private function som(int $tiyin): string
    {
        return number_format($tiyin / 100, 2, '.', '');
    }
}
