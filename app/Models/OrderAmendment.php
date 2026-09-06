<?php

namespace App\Models;

use App\Enums\AmendmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed change to an active deal (pricelist and/or deadline) that must be
 * approved by every required party before it is written into the offer. The
 * before/after snapshots make each amendment a self-contained, auditable record.
 */
class OrderAmendment extends Model
{
    use HasFactory;

    public const ROLE_CLIENT = 'client';

    public const ROLE_AGENT = 'agent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'offer_id',
        'initiator_id',
        'initiator_role',
        'before_snapshot',
        'after_snapshot',
        'reason',
        'extra_amount',
        'requires_operator',
        'requires_formal_doc',
        'status',
        'client_approved_at',
        'agent_approved_at',
        'operator_approved_at',
        'applied_at',
        'rejected_by',
        'rejection_reason',
        'payment_id',
        'pdf_file_id',
        'hash',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'pdf_file_id');
    }

    public function hasExtraPayment(): bool
    {
        return (float) $this->extra_amount > 0;
    }

    /** All required parties have approved (operator only when flagged). */
    public function isFullyApproved(): bool
    {
        if ($this->client_approved_at === null || $this->agent_approved_at === null) {
            return false;
        }

        return ! $this->requires_operator || $this->operator_approved_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'extra_amount' => 'decimal:2',
            'requires_operator' => 'boolean',
            'requires_formal_doc' => 'boolean',
            'status' => AmendmentStatus::class,
            'client_approved_at' => 'datetime',
            'agent_approved_at' => 'datetime',
            'operator_approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
