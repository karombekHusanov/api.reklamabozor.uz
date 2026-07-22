<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use Database\Factories\PayoutFactory;
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
        'meta',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
            'meta' => 'array',
        ];
    }
}
