<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a manager closed a problem order: a manual refund (amount judged by a
 * human, often partial — the money moves outside the platform; this is the
 * audit entry, not a ledger transaction) or a dismissal back to the deal.
 */
class OrderProblemResolution extends Model
{
    use HasFactory;

    public const REFUNDED = 'refunded';

    public const DISMISSED = 'dismissed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'resolution',
        'refund_amount',
        'refund_method',
        'reference',
        'note',
        'resolved_by',
        'resolved_at',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }
}
