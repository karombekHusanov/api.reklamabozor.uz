<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OrderDeadline;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

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
        'title',
        'description',
        'deadline',
        'tz_file_id',
        'attachment_file_ids',
        'budget_min',
        'budget_max',
        'status',
        'work_submitted_at',
        'completion_reminder_sent_at',
        'completed_at',
        'auto_completed',
    ];

    /**
     * Eager loads needed to render an order for the client.
     *
     * @var list<string>
     */
    public const CLIENT_RELATIONS = [
        'category',
        'targetAgent.agentProfile',
        'offers.agentProfile.companyLogoFile',
        'review',
        'latestPayment',
    ];

    /** Max files a client may attach to one order. */
    public const MAX_ATTACHMENTS = 5;

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Payment attempts against this order (Multicard hosted checkout).
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'status' => OrderStatus::class,
            'deadline' => OrderDeadline::class,
            'attachment_file_ids' => 'array',
            'work_submitted_at' => 'datetime',
            'completion_reminder_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'auto_completed' => 'boolean',
        ];
    }
}
