<?php

namespace App\Models;

use App\Enums\AmendmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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

    public const ROLE_OPERATOR = 'operator';

    /** Refund lifecycle for a price-lowering addendum. */
    public const REFUND_NONE = 'none';

    public const REFUND_DUE = 'due';

    public const REFUND_REFUNDED = 'refunded';

    public const REFUND_WAIVED = 'waived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'offer_id',
        'number',
        'sequence',
        'contract_id',
        'initiator_id',
        'initiator_role',
        'client_window_ends_at',
        'expires_at',
        'reminder_sent_at',
        'before_snapshot',
        'after_snapshot',
        'reason',
        'extra_amount',
        'refund_amount',
        'refund_state',
        'refund_method',
        'refund_reference',
        'refund_note',
        'refunded_by',
        'refunded_at',
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
        'document_hash',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** The service contract this addendum belongs to. */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Click-wrap acceptances of this addendum (client / agent / operator).
     *
     * @return HasMany<ContractAcceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(ContractAcceptance::class, 'amendment_id')->latest('accepted_at');
    }

    /** When the given party accepted this addendum (latest wins). */
    public function acceptedAt(string $party): ?Carbon
    {
        $rows = $this->relationLoaded('acceptances') ? $this->acceptances : $this->acceptances()->get();

        return $rows->firstWhere('party', $party)?->accepted_at;
    }

    /**
     * Audit trail — every decision and money movement on this addendum.
     *
     * @return HasMany<AmendmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AmendmentEvent::class, 'amendment_id')->oldest();
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

    /** Money the platform still has to hand back for this addendum. */
    public function refundIsDue(): bool
    {
        return $this->refund_state === self::REFUND_DUE && (float) $this->refund_amount > 0;
    }

    public function refundWasSettled(): bool
    {
        return $this->refund_state === self::REFUND_REFUNDED;
    }

    /**
     * The party that still has to answer this proposal, or null when everyone
     * required has decided.
     */
    public function awaitingParty(): ?string
    {
        if ($this->client_approved_at === null) {
            return self::ROLE_CLIENT;
        }

        if ($this->agent_approved_at === null) {
            return self::ROLE_AGENT;
        }

        if ($this->requires_operator && $this->operator_approved_at === null) {
            return self::ROLE_OPERATOR;
        }

        return null;
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
            'refund_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'client_window_ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
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
