<?php

namespace App\Services\Admin;

use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
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
     * Period totals for the finance overview.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        // Query builder, not Eloquent: the grouped rows are aggregates, and a
        // cast enum column would blow up on the way out.
        $collectedByMethod = DB::table('payments')
            ->where('status', PaymentStatus::Success->value)
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
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'collected' => $this->money($collected),
            'collected_by_method' => $collectedByMethod,
            'refunded' => $this->money($refunded),
            'net_collected' => $this->money($collected - $refunded),
            'commission' => $this->money($this->commissionAccrued($from, $to)),
            'paid_to_agents' => $this->money($paidOut),
            'pending_payouts' => $this->money($pendingPayouts),
            'receivables' => $this->money($this->receivables()),
            'counts' => [
                'payments' => Payment::query()
                    ->where('status', PaymentStatus::Success)
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
            'created_at' => $payment->created_at?->format('d.m.Y H:i') ?? '',
            'paid_at' => $payment->paid_at?->format('d.m.Y H:i') ?? '',
            'order_id' => (string) $payment->payable_id,
            'order_title' => (string) ($payment->payable?->title ?? ''),
            'payer' => trim(($payment->payer?->first_name ?? '').' '.($payment->payer?->last_name ?? '')),
            'payer_phone' => (string) ($payment->payer?->phone ?? ''),
            'method' => (string) $payment->method?->value,
            'status' => $payment->status->value,
            'amount_som' => $this->som($payment->amount),
            'reference' => (string) ($payment->reference ?? $payment->gateway_uuid ?? ''),
            'refunded_at' => $payment->refunded_at?->format('d.m.Y H:i') ?? '',
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
            'created_at' => $payout->created_at?->format('d.m.Y') ?? '',
            'paid_at' => $payout->paid_at?->format('d.m.Y') ?? '',
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
        $percent = (float) config('services.multicard.commission_percent', 0);

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
            $query->where($column, '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where($column, '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }
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
