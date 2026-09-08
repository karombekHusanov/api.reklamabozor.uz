<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One party's click-wrap acceptance of the per-order service contract (append
 * only — a revised pricelist produces a new acceptance rather than editing one).
 */
class ContractAcceptance extends Model
{
    use HasFactory;

    public const PARTY_CLIENT = 'client';

    public const PARTY_AGENT = 'agent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'offer_id',
        'amendment_id',
        'user_id',
        'party',
        'version',
        'terms_version',
        'total',
        'snapshot',
        'hash',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** Set when this acceptance is for an addendum rather than the contract. */
    public function amendment(): BelongsTo
    {
        return $this->belongsTo(OrderAmendment::class, 'amendment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'snapshot' => 'array',
            'accepted_at' => 'datetime',
        ];
    }
}
