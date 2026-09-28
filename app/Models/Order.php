<?php

namespace App\Models;

use App\Enums\AmendmentStatus;
use App\Enums\CategoryType;
use App\Enums\OfferStatus;
use App\Enums\OrderDeadline;
use App\Enums\OrderPaymentState;
use App\Enums\OrderProblemReason;
use App\Enums\OrderProblemState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\ReviewDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'target_agent_id',
        'category_id',
        'category_type',
        'title',
        'description',
        'deadline',
        'tz_file_id',
        'attachment_file_ids',
        'show_files_in_showcase',
        'budget_min',
        'budget_max',
        'lat',
        'lng',
        'location_label',
        'region_id',
        'district_id',
        'status',
        'route',
        'claimed_agent_id',
        'claimed_at',
        'payment_state',
        'activated_at',
        'payment_due_at',
        'payment_reminded_at',
        'paid_at',
        'awaiting_payment_at',
        'work_submitted_at',
        'completion_reminder_sent_at',
        'completed_at',
        'auto_completed',
        'disputed_at',
        'problem_state',
        'problem_reason',
        'problem_flagged_at',
        'correction_deadline_at',
        'correction_reminder_sent_at',
        'problem_resolved_at',
        'stale_reminder_sent_at',
    ];

    /**
     * Eager loads needed to render an order for the client.
     *
     * @var list<string>
     */
    public const CLIENT_RELATIONS = [
        'category',
        'region',
        'district',
        'hashtags',
        'targetAgent.profile',
        'claimedAgent.profile.companyLogoFile',
        'claimedAgent.profile.cachedRating',
        'offers.agentProfile.companyLogoFile',
        'offers.agentProfile.cachedRating',
        'offers.agent.avatarFile',
        'offers.items',
        'offers.contractAcceptances',
        'acceptedOffer.agentProfile',
        'review',
        'providerReview',
        'latestPayment',
        'contract.pdfFile',
        'documents.pdfFile',
    ];

    /** Max files a client may attach to one order. */
    public const MAX_ATTACHMENTS = 5;

    /** Max hashtags a client may attach to one order. */
    public const MAX_HASHTAGS = 5;

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * The single agency this order was directed to (from its public profile),
     * or null for a normal broadcast order.
     */
    public function targetAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_agent_id');
    }

    /**
     * Tezkor: the agency the client picked among the otkliks ("Kelishildi").
     * Set only when the request is closed — until then any paying agent may
     * respond. (Column name kept from the retired exclusive-claim model.)
     */
    public function claimedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_agent_id');
    }

    public function isTezkor(): bool
    {
        return $this->route === OrderRoute::Tezkor;
    }

    public function isTender(): bool
    {
        return ! $this->isTezkor();
    }

    /** Tezkor: the client has picked an agency (the request is closed). */
    public function isClaimed(): bool
    {
        return $this->claimed_agent_id !== null;
    }

    /**
     * Guard for everything that only makes sense on the Tender route (priced
     * accept, pricelist, contract, payment, payout, amendments).
     */
    public function assertTender(): void
    {
        if ($this->isTezkor()) {
            throw ValidationException::withMessages([
                'order' => ['This action is not available for Tezkor requests.'],
            ]);
        }
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'district_id');
    }

    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'order_hashtag')->withTimestamps();
    }

    public function tzFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'tz_file_id');
    }

    /**
     * All file ids for this order, including legacy rows that still store the
     * first upload in tz_file_id until the data migration has run.
     *
     * @return list<int>
     */
    public function allAttachmentFileIds(): array
    {
        $ids = $this->attachment_file_ids ?? [];

        if ($this->tz_file_id !== null && ! in_array($this->tz_file_id, $ids, true)) {
            array_unshift($ids, $this->tz_file_id);
        }

        return $ids;
    }

    /**
     * Hydrate the virtual `attachmentFiles` relation from stored file ids.
     * Batched across a collection to avoid N+1 lookups.
     *
     * @param  Order|EloquentCollection<int, Order>|Collection<int, Order>  $orders
     */
    public static function hydrateAttachmentFiles(Order|EloquentCollection|Collection $orders): void
    {
        if ($orders instanceof Order) {
            self::hydrateAttachmentFiles(new EloquentCollection([$orders]));

            return;
        }

        if ($orders->isEmpty()) {
            return;
        }

        $allIds = $orders
            ->flatMap(fn (Order $order) => $order->allAttachmentFileIds())
            ->unique()
            ->values();

        $filesById = $allIds->isEmpty()
            ? collect()
            : File::query()->whereIn('id', $allIds)->get()->keyBy('id');

        foreach ($orders as $order) {
            $files = collect($order->allAttachmentFileIds())
                ->map(fn (int $id) => $filesById->get($id))
                ->filter()
                ->values();

            $order->setRelation('attachmentFiles', new EloquentCollection($files->all()));
        }
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(OrderView::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function acceptedOffer(): HasOne
    {
        return $this->hasOne(Offer::class)->where('status', OfferStatus::Accepted);
    }

    public function chat(): HasOne
    {
        return $this->hasOne(Chat::class);
    }

    /**
     * Additional agreements (Qo'shimcha kelishuv) proposed on this deal.
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(OrderAmendment::class)->latest();
    }

    /**
     * The generated per-order service contract (present once the deal started).
     */
    /**
     * Accounting documents generated when the order completed (acts).
     *
     * @return HasMany<OrderDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    /**
     * Audit trail for this order's problem-queue history (flagged / refunded /
     * dismissed).
     */
    public function problemEvents(): HasMany
    {
        return $this->hasMany(OrderProblemEvent::class);
    }

    /**
     * How each problem-queue episode on this order was closed.
     */
    public function problemResolutions(): HasMany
    {
        return $this->hasMany(OrderProblemResolution::class);
    }

    /**
     * The client's review of the provider (backward-compat single review).
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class)
            ->where('direction', ReviewDirection::ClientToProvider);
    }

    /**
     * The provider's review of the client.
     */
    public function providerReview(): HasOne
    {
        return $this->hasOne(Review::class)
            ->where('direction', ReviewDirection::ProviderToClient);
    }

    /**
     * Both reviews on this order (client→provider and provider→client).
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Payment attempts against this order (cash / bank transfer).
     *
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * The latest order payment (the one the client is settling), if any.
     *
     * @return MorphOne<Payment, $this>
     */
    public function latestPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNew(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::New);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByStatus(Builder $query, OrderStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof OrderStatus ? $status : OrderStatus::from($status));
    }

    /** Days after which an untouched in-progress order counts as stuck. */
    public const STUCK_AFTER_DAYS = 7;

    /** Hours after which an order without offers counts as dead. */
    public const NO_OFFERS_AFTER_HOURS = 24;

    /**
     * Active deals that have not been touched for a week — ops attention needed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStuck(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [OrderStatus::InProgress, OrderStatus::WorkSubmitted])
            ->where('updated_at', '<', now()->subDays(self::STUCK_AFTER_DAYS));
    }

    /**
     * Orders past the grace window that never received an offer.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutOffers(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [OrderStatus::New, OrderStatus::OffersSent])
            ->where('created_at', '<', now()->subHours(self::NO_OFFERS_AFTER_HOURS))
            ->doesntHave('offers');
    }

    // --- Payment ledger ---------------------------------------------------
    //
    // The money side is derived, never hand-set: what the deal costs comes from
    // the accepted offer (which an applied amendment rewrites), what came in is
    // the sum of settled payments. `payment_state` follows from the difference.

    /** What this deal costs right now, in tiyin (accepted offer total). */
    public function dueTiyin(): int
    {
        $offer = $this->relationLoaded('acceptedOffer')
            ? $this->acceptedOffer
            : $this->acceptedOffer()->first();

        if ($offer?->price === null) {
            return 0;
        }

        return (int) round(((float) $offer->price) * 100);
    }

    /**
     * Money actually settled for this deal, in tiyin: the order's own payments
     * plus any extra charged through its amendments. Reverted payments are not
     * `success`, so a refund drops out of the sum on its own.
     */
    public function paidTiyin(): int
    {
        $amendmentIds = $this->amendments()->pluck('id');

        // Money handed back through a settled addendum refund leaves the ledger
        // (the gateway cannot reverse it, a manager returns it by hand).
        $refunded = (float) $this->amendments()
            ->where('refund_state', OrderAmendment::REFUND_REFUNDED)
            ->sum('refund_amount');

        $settled = (int) Payment::query()
            ->where('status', PaymentStatus::Success)
            ->where(function ($query) use ($amendmentIds): void {
                $query->where(function ($q): void {
                    $q->where('payable_type', $this->getMorphClass())
                        ->where('payable_id', $this->id)
                        ->where('purpose', PaymentPurpose::Order);
                });

                if ($amendmentIds->isNotEmpty()) {
                    $query->orWhere(function ($q) use ($amendmentIds): void {
                        $q->where('payable_type', (new OrderAmendment)->getMorphClass())
                            ->whereIn('payable_id', $amendmentIds)
                            ->where('purpose', PaymentPurpose::Amendment);
                    });
                }
            })
            ->sum('amount');

        return $settled - (int) round($refunded * 100);
    }

    /** Still owed, in tiyin. Negative means the client overpaid (owed a refund). */
    public function outstandingTiyin(): int
    {
        return $this->dueTiyin() - $this->paidTiyin();
    }

    /**
     * Re-derive `payment_state` from the ledger. Called whenever money settles
     * or the deal amount changes (an applied amendment).
     *
     * Terminal-ish states are left alone: `not_required` means this deal never
     * collects (gateway off), `refunded` belongs to a cancelled/reverted order.
     */
    public function recalculatePaymentState(): void
    {
        if (in_array($this->payment_state, [
            OrderPaymentState::NotRequired,
            OrderPaymentState::Refunded,
        ], true)) {
            return;
        }

        // Overpayment (an amendment cut the price after payment) settles as paid
        // here; the refund obligation itself is tracked on the amendment.
        if ($this->outstandingTiyin() > 0) {
            $this->update(['payment_state' => OrderPaymentState::Unpaid]);

            return;
        }

        $this->update([
            'payment_state' => OrderPaymentState::Paid,
            'paid_at' => $this->paid_at ?? now(),
            'payment_due_at' => null,
            'payment_reminded_at' => null,
        ]);
    }

    // --- Amendment window -------------------------------------------------

    /**
     * End of the client's window for proposing an additional agreement: the
     * first slice (default a third) of the committed delivery time, measured
     * from activation. Null when it cannot be computed (deal not started).
     */
    public function amendmentWindowEndsAt(): ?Carbon
    {
        $start = $this->activated_at;

        if ($start === null) {
            return null;
        }

        $offer = $this->relationLoaded('acceptedOffer')
            ? $this->acceptedOffer
            : $this->acceptedOffer()->first();

        $days = $offer?->deadline_days !== null
            ? (int) ceil((int) $offer->deadline_days / max(1, (int) config('orders.amendment_client_window_divisor', 3)))
            : (int) config('orders.amendment_client_window_fallback_days', 3);

        return $start->copy()->addDays(max(1, $days));
    }

    /**
     * Why this user may (not) propose an amendment right now.
     *
     * Returns one of: `ok`, `not_active`, `not_participant`, `pending_exists`,
     * `window_closed`. The agent doing the work is never time-limited.
     */
    public function amendmentProposalState(?User $user): string
    {
        if ($this->status !== OrderStatus::InProgress) {
            return 'not_active';
        }

        if ($this->amendments()->where('status', AmendmentStatus::Pending)->exists()) {
            return 'pending_exists';
        }

        $offer = $this->relationLoaded('acceptedOffer')
            ? $this->acceptedOffer
            : $this->acceptedOffer()->first();

        if ($user === null) {
            return 'not_participant';
        }

        if ($user->id === $offer?->agent_id) {
            return 'ok'; // the executor may always propose a change
        }

        if ($user->id !== $this->client_id) {
            return 'not_participant';
        }

        $endsAt = $this->amendmentWindowEndsAt();

        return $endsAt === null || $endsAt->isFuture() ? 'ok' : 'window_closed';
    }

    /**
     * When the platform may start releasing payouts on this order: the end of
     * the client's cooling-off window (null = right away, nothing was paid).
     */
    public function payoutsUnlockAt(): ?Carbon
    {
        return $this->clientCancelDeadline();
    }

    /** Whether payouts on this order are still frozen by the cancel window. */
    public function payoutsLocked(): bool
    {
        $unlockAt = $this->payoutsUnlockAt();

        return $unlockAt !== null && $unlockAt->isFuture();
    }

    public function canProposeAmendment(?User $user): bool
    {
        return $this->amendmentProposalState($user) === 'ok';
    }

    /**
     * Deadline for the client's own cancellation, or null when the order can be
     * cancelled without a time limit (nothing has been paid yet).
     *
     * The same moment gates the agent's advance payout: money only moves once
     * the client can no longer pull it back ({@see Order::payoutsUnlockAt()}).
     */
    public function clientCancelDeadline(): ?Carbon
    {
        if ($this->payment_state !== OrderPaymentState::Paid) {
            return null;
        }

        $minutes = max(1, (int) config('orders.paid_cancel_window_minutes', 60));

        // Measured from the moment the deal was first settled — a later top-up
        // (an amendment's extra) does not reopen the right to cancel.
        return ($this->paid_at ?? $this->updated_at)?->copy()->addMinutes($minutes);
    }

    /**
     * May the client still cancel this order themselves?
     *
     * - open for offers / legacy awaiting_payment → yes;
     * - active but unpaid → yes (no money moved);
     * - active and paid → only inside the cooling-off window, and only while no
     *   agent payout has actually been released;
     * - delivered, completed or cancelled → no.
     */
    public function isCancellableByClient(): bool
    {
        if ($this->status->isOpenForOffers() || $this->status === OrderStatus::AwaitingPayment) {
            return true;
        }

        if ($this->status !== OrderStatus::InProgress) {
            return false;
        }

        if ($this->payment_state !== OrderPaymentState::Paid) {
            return true;
        }

        // Money already handed to the agent — support has to sort it out.
        if ($this->payouts()->where('status', PayoutStatus::Paid)->exists()) {
            return false;
        }

        $deadline = $this->clientCancelDeadline();

        return $deadline === null || $deadline->isFuture();
    }

    // --- Problem orders ----------------------------------------------------
    //
    // Second risk scenario (the first, a stalled quality dispute, is flagged
    // by the scheduled sweep — see OrderProblemService::flagFromDispute()):
    // the client reports an agent who took the advance and never started.

    /**
     * When the client may report "agent took the advance, never started" —
     * not before the platform gives the agent a fair grace period.
     */
    public function noStartReportEligibleAt(): ?Carbon
    {
        if ($this->activated_at === null) {
            return null;
        }

        $days = max(0, (int) config('orders.no_start_report_min_days', 3));

        return $this->activated_at->copy()->addDays($days);
    }

    /**
     * True only when every condition holds: the deal is active and paid, the
     * agent has not submitted anything yet, no problem report is already open
     * or resolved on this order, and the grace period has passed.
     */
    public function canReportNoStart(): bool
    {
        if ($this->status !== OrderStatus::InProgress || $this->payment_state !== OrderPaymentState::Paid) {
            return false;
        }

        if ($this->work_submitted_at !== null) {
            return false;
        }

        if ($this->problem_state !== OrderProblemState::None) {
            return false;
        }

        $eligibleAt = $this->noStartReportEligibleAt();

        return $eligibleAt !== null && now()->greaterThanOrEqualTo($eligibleAt);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'status' => OrderStatus::class,
            'route' => OrderRoute::class,
            'claimed_at' => 'datetime',
            'payment_state' => OrderPaymentState::class,
            'activated_at' => 'datetime',
            'payment_due_at' => 'datetime',
            'payment_reminded_at' => 'datetime',
            'paid_at' => 'datetime',
            'category_type' => CategoryType::class,
            'deadline' => OrderDeadline::class,
            'attachment_file_ids' => 'array',
            'show_files_in_showcase' => 'boolean',
            'awaiting_payment_at' => 'datetime',
            'work_submitted_at' => 'datetime',
            'completion_reminder_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'auto_completed' => 'boolean',
            'disputed_at' => 'datetime',
            'problem_state' => OrderProblemState::class,
            'problem_reason' => OrderProblemReason::class,
            'problem_flagged_at' => 'datetime',
            'correction_deadline_at' => 'datetime',
            'correction_reminder_sent_at' => 'datetime',
            'problem_resolved_at' => 'datetime',
            'stale_reminder_sent_at' => 'datetime',
        ];
    }
}
