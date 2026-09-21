<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Offer extends Model
{
    use HasFactory;

    /** Hard cap on price edits while the offer is still pending. */
    public const MAX_PRICE_EDITS = 5;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'agent_id',
        'agent_profile_id',
        'price',
        'comment',
        'deadline_days',
        'status',
        'price_updated_at',
        'price_edit_count',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    /**
     * Pricelist lines the agent sent for this offer (ordered).
     *
     * @return HasMany<OfferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Click-wrap acceptances of the per-order contract for this offer
     * (agent when the pricelist was sent, client when the offer was accepted).
     *
     * @return HasMany<ContractAcceptance, $this>
     */
    public function contractAcceptances(): HasMany
    {
        return $this->hasMany(ContractAcceptance::class)->latest('accepted_at');
    }

    /**
     * When the given party accepted the contract for this offer (latest wins).
     */
    public function contractAcceptedAt(string $party): ?Carbon
    {
        $acceptances = $this->relationLoaded('contractAcceptances')
            ? $this->contractAcceptances
            : $this->contractAcceptances()->get();

        return $acceptances->firstWhere('party', $party)?->accepted_at;
    }

    /**
     * Recompute and persist `price` as the sum of the pricelist line totals.
     * A pricelist with no rows leaves the offer as an interest (price = null).
     */
    public function recomputeTotal(): void
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            $this->update(['price' => null]);

            return;
        }

        $total = $items->reduce(
            fn (string $carry, OfferItem $item): string => bcadd($carry, $item->lineTotal(), 2),
            '0',
        );

        $this->update(['price' => $total]);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Otklik / interest stub — no price attached yet (Faza 1).
     */
    public function isInterest(): bool
    {
        return $this->price === null;
    }

    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    /**
     * Client may accept only a priced, pending offer while the order is still
     * awaiting selection (new / offers_sent).
     */
    public function canAccept(): bool
    {
        if (! $this->hasPrice() || $this->status !== OfferStatus::Pending) {
            return false;
        }

        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        if ($order === null || $order->isTezkor()) {
            return false;
        }

        return in_array($order->status, [OrderStatus::New, OrderStatus::OffersSent], true);
    }

    public function canEditPrice(): bool
    {
        // Interests cannot be priced via PATCH — contract / priced path comes later.
        if (! $this->hasPrice()) {
            return false;
        }

        if ($this->status !== OfferStatus::Pending) {
            return false;
        }

        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        if ($order === null || ! $order->status->isOpenForOffers()) {
            return false;
        }

        return (int) $this->price_edit_count < self::MAX_PRICE_EDITS;
    }

    public function priceEditsRemaining(): int
    {
        return max(0, self::MAX_PRICE_EDITS - (int) $this->price_edit_count);
    }

    /**
     * The agent may pull back their own offer while it is still pending — once
     * the client accepts a different one, this offer is auto-rejected and can
     * no longer be withdrawn (nothing left to pull back).
     */
    public function canWithdraw(): bool
    {
        return $this->status === OfferStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OfferStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', OfferStatus::Accepted);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'deadline_days' => 'integer',
            'status' => OfferStatus::class,
            'price_updated_at' => 'datetime',
            'price_edit_count' => 'integer',
        ];
    }
}
