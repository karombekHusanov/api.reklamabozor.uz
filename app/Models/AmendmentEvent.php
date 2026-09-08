<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in an amendment's audit trail (see the admin timeline).
 */
class AmendmentEvent extends Model
{
    use HasFactory;

    public const PROPOSED = 'proposed';

    public const APPROVED = 'approved';

    public const OPERATOR_APPROVED = 'operator_approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const APPLIED = 'applied';

    public const DOCUMENT_GENERATED = 'document_generated';

    public const CHARGE_DUE = 'charge_due';

    public const REFUND_DUE = 'refund_due';

    public const REFUND_PAID = 'refund_paid';

    public const REFUND_WAIVED = 'refund_waived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'amendment_id',
        'actor_id',
        'actor_role',
        'type',
        'payload',
        'ip_address',
    ];

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(OrderAmendment::class, 'amendment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
