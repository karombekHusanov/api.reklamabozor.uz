<?php

namespace App\Models;

use App\Enums\OrderPaymentState;
use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single release of an order's escrow to the agent (advance / final /
 * adjustment). Amounts are in tiyin, mirroring the payments table.
 */
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'withdrawal_id',
        'agent_profile_id',
        'agent_id',
        'tranche',
        'amount',
        'currency',
        'status',
        'method',
        'gateway_uuid',
        'reference',
        'released_by',
        'paid_at',
        'notified_at',
        'meta',
    ];

    /**
     * Payouts a manager may transfer right now: still pending, and the client's
     * cooling-off window on the order has closed ({@see Order::payoutsLocked()}).
     *
     * @param  Builder<Payout>  $query
     * @return Builder<Payout>
     */
    public function scopeReleasable(Builder $query): Builder
    {
        $minutes = max(1, (int) config('orders.paid_cancel_window_minutes', 60));

        return $query
            ->where('status', PayoutStatus::Pending->value)
            ->whereHas('order', fn (Builder $order): Builder => $order->where(
                fn (Builder $q): Builder => $q
                    ->where('payment_state', '!=', OrderPaymentState::Paid->value)
                    ->orWhereNull('paid_at')
                    ->orWhere('paid_at', '<=', now()->subMinutes($minutes)),
            ));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** Amount in som (display), derived from the tiyin column. */
    public function amountSom(): float
    {
        return $this->amount / 100;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PayoutStatus::class,
            'tranche' => PayoutTranche::class,
            'paid_at' => 'datetime',
            'notified_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
