<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An on-demand cash-out of an agent's escrow balance to their card, via the
 * Multicard hosted bind form → `credit` → OTP flow. Aggregates one or more
 * pending payouts (reserved as `processing` while the withdrawal is in flight).
 * Amounts are in tiyin, mirroring payments/payouts.
 */
class Withdrawal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'agent_profile_id',
        'method',
        'amount',
        'currency',
        'status',
        'session_id',
        'form_url',
        'card_token',
        'card_pan',
        'ps',
        'gateway_uuid',
        'failure_reason',
        'paid_at',
        'meta',
    ];

    /**
     * Never expose the transient card token over the API.
     *
     * @var list<string>
     */
    protected $hidden = ['card_token'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
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
            'status' => WithdrawalStatus::class,
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
