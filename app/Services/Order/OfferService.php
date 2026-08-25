<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\ReviewDirection;
use App\Models\Chat;
use App\Models\DirectChatMessage;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderView;
use App\Models\User;
use App\Services\Chat\DirectChatService;
use App\Services\Payout\PayoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly PayoutService $payouts,
        private readonly DirectChatService $directChats,
        private readonly OrderContractService $contracts,
    ) {}

    /**
     * Orders an approved agent may bid on: open for offers, and either in one
     * of the agent's categories or a broadcast order ("Other" / empty category
     * with no approved providers). Each order carries the agent's own offer
     * (if any).
     *
     * @param  int|null  $orderId  When set, return at most that one opportunity.
     * @return Collection<int, Order>
     */
    public function availableForAgent(User $agent, ?int $orderId = null): Collection
    {
        // Categories served by the agent's approved profile (1 user = 1 profile).
        $profile = $agent->profile()
            ->where('status', AgentProfileStatus::Approved)
            ->with('categories')
            ->first();

        if ($profile === null) {
            return new Collection;
        }

        $categoryIds = $profile->categories->pluck('id')->values();

        $orders = Order::query()
            ->where(function ($query) use ($categoryIds): void {
                $query->whereIn('category_id', $categoryIds)
                    ->orWhereHas('category', function ($categoryQuery): void {
                        // Catch-all "Boshqa" always open to every approved provider.
                        $categoryQuery->where('is_other', true);
                    })
                    ->orWhereHas('category', function ($categoryQuery): void {
                        // Normal category with nobody approved to serve it yet.
                        $categoryQuery
                            ->where('is_other', false)
                            ->whereDoesntHave(
                                'agentProfiles',
                                fn ($profileQuery) => $profileQuery->where('status', AgentProfileStatus::Approved),
                            );
                    });
            })
            // Broadcast orders (no target) are open to all; a directed order only
            // ever shows to the single agency it was addressed to.
            ->where(fn ($q) => $q->whereNull('target_agent_id')->orWhere('target_agent_id', $agent->id))
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::openForOffers()))
            ->when($orderId !== null, fn ($q) => $q->whereKey($orderId))
            ->withCount(['views', 'offers'])
            ->with([
                'category',
                'region',
                'district',
                'hashtags',
                'client.avatarFile',
                'offers' => fn ($query) => $query->where('agent_id', $agent->id)->with('items'),
            ])
            ->latest()
            ->get();

        $this->recordViews($agent, $orders);
        Order::hydrateAttachmentFiles($orders);

        return $orders;
    }

    /**
     * Single open opportunity the agent may bid on (404 if outside their feed).
     */
    public function findAvailableForAgent(User $agent, Order $order): Order
    {
        $found = $this->availableForAgent($agent, $order->id)->first();

        abort_unless($found !== null, 404);

        return $found;
    }

    /**
     * Mark each listed order as viewed by this agent (distinct — one row per
     * order+viewer). Idempotent via the unique index, so repeat loads don't
     * inflate the count.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function recordViews(User $agent, Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $orders->map(fn (Order $order): array => [
            'order_id' => $order->id,
            'user_id' => $agent->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        OrderView::upsert($rows, ['order_id', 'user_id'], ['updated_at']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitOffer(User $agent, Order $order, array $data): Offer
    {
        $order->loadMissing('category');

        // No self-dealing: a user who is both the client and a provider must not
        // bid on their own order (would let them drive their own payout / inflate
        // their stats). One account can hold both capacities, so guard explicitly.
        if ($order->client_id === $agent->id) {
            throw ValidationException::withMessages([
                'order' => ['You cannot send an offer to your own order.'],
            ]);
        }

        // Prefer the profile that lists this category; for broadcast orders
        // ("Other" / empty category) fall back to any approved profile.
        $profile = $agent->providerProfileForCategory($order->category_id)
            ?? $agent->providerProfileForBroadcastOrder($order);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'order' => ['This order is outside your service categories.'],
            ]);
        }

        if (! $order->status->isOpenForOffers()) {
            throw ValidationException::withMessages([
                'order' => ['This order is no longer accepting offers.'],
            ]);
        }

        if ($order->offers()->where('agent_id', $agent->id)->exists()) {
            throw ValidationException::withMessages([
                'order' => ['You have already sent an offer for this order.'],
            ]);
        }

        /** @var Offer $offer */
        $offer = $order->offers()->create([
            'agent_id' => $agent->id,
            'agent_profile_id' => $profile->id,
            'price' => $data['price'] ?? null,
            'comment' => $data['comment'] ?? null,
            'status' => OfferStatus::Pending,
        ]);

        if ($order->status === OrderStatus::New) {
            $order->update(['status' => OrderStatus::OffersSent]);
        }

        // Eager-open the order-scoped thread for interests so the client notify
        // can deep-link into chat (priced offers still open chat on demand).
        if ($offer->isInterest()) {
            try {
                $this->directChats->openForOffer($agent, $offer);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $this->notifier->notifyNewOffer($offer);
        } catch (\Throwable $e) {
            report($e);
        }

        return $offer->load(['agent', 'agentProfile.companyLogoFile', 'order']);
    }

    /**
     * @return Collection<int, Offer>
     */
    public function listForAgent(User $agent): Collection
    {
        return Offer::query()
            ->where('agent_id', $agent->id)
            ->with([
                'items',
                'order' => fn ($q) => $q->withCount(['views', 'offers']),
                'order.category',
                'order.region',
                'order.district',
                'order.hashtags',
                'order.client.avatarFile',
                'order.reviews' => fn ($q) => $q->where('direction', ReviewDirection::ProviderToClient)
                    ->where('reviewer_id', $agent->id),
            ])
            ->latest()
            ->get();
    }

    /**
     * Single offer owned by the agent, with full order context for the detail page.
     */
    public function findForAgent(User $agent, Offer $offer): Offer
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        $offer->load([
            'items',
            'order.category',
            'order.region',
            'order.district',
            'order.hashtags',
            'order.client.avatarFile',
            'order.contract.pdfFile',
            'order.reviews' => fn ($q) => $q->where('direction', ReviewDirection::ProviderToClient)
                ->where('reviewer_id', $agent->id),
        ]);

        if ($offer->order) {
            $offer->order->loadCount(['views', 'offers']);
            Order::hydrateAttachmentFiles($offer->order);
        }

        return $offer;
    }

    /**
     * Agent adjusts the bid while it is still pending (max {@see Offer::MAX_PRICE_EDITS}).
     *
     * @param  array{price: float|int|string, comment?: string|null}  $data
     */
    public function updatePrice(User $agent, Offer $offer, array $data): Offer
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        $offer->loadMissing('order');

        if ($offer->isInterest()) {
            throw ValidationException::withMessages([
                'price' => ['Interest responses cannot be priced via edit.'],
            ]);
        }

        if (! $offer->canEditPrice()) {
            if ($offer->status !== OfferStatus::Pending) {
                throw ValidationException::withMessages([
                    'price' => ['This offer can no longer be edited.'],
                ]);
            }

            if ((int) $offer->price_edit_count >= Offer::MAX_PRICE_EDITS) {
                throw ValidationException::withMessages([
                    'price' => ['Price edit limit reached ('.Offer::MAX_PRICE_EDITS.').'],
                ]);
            }

            throw ValidationException::withMessages([
                'price' => ['This order is no longer open for negotiation.'],
            ]);
        }

        $newPrice = (string) $data['price'];
        $oldPrice = (string) $offer->price;

        if (bccomp($newPrice, $oldPrice, 2) === 0) {
            throw ValidationException::withMessages([
                'price' => ['Enter a different price.'],
            ]);
        }

        if (isset($data['comment']) && $data['comment'] !== null) {
            $comment = trim((string) $data['comment']);
            if ($comment === '') {
                throw ValidationException::withMessages([
                    'comment' => ['Comment cannot be empty.'],
                ]);
            }
        } else {
            $comment = $offer->comment;
        }

        $offer->update([
            'price' => $newPrice,
            'comment' => $comment,
            'price_updated_at' => now(),
            'price_edit_count' => (int) $offer->price_edit_count + 1,
        ]);

        $order = $offer->order;
        $chat = $this->directChats->findPair($order->client_id, $agent->id, $order->id)
            ?? $this->directChats->openForOffer($agent, $offer->fresh());

        $this->directChats->postEvent(
            $chat,
            $agent,
            DirectChatMessage::TYPE_OFFER_PRICE_CHANGED,
            'Offer price updated',
            [
                'order_id' => $order->id,
                'offer_id' => $offer->id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
            ],
        );

        try {
            $this->notifier->notifyOfferPriceChanged($offer->fresh(['order', 'agent']));
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->findForAgent($agent, $offer->fresh());
    }

    /**
     * Agent sends (or replaces) the pricelist on their pending offer — the
     * priced step after negotiating in the order chat. Replaces every line,
     * recomputes the cached total (interest → priced), posts an event into the
     * order-scoped thread, and notifies the client.
     *
     * @param  array<int, array{name: string, unit?: string|null, quantity: float|int|string, unit_price: float|int|string}>  $items
     */
    public function setPricelist(User $agent, Offer $offer, array $items): Offer
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        $offer->loadMissing('order');
        $order = $offer->order;

        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => ['This order is no longer available.'],
            ]);
        }

        if ($offer->status !== OfferStatus::Pending || ! $order->status->isOpenForOffers()) {
            throw ValidationException::withMessages([
                'offer' => ['This offer can no longer be priced.'],
            ]);
        }

        DB::transaction(function () use ($offer, $items): void {
            $offer->items()->delete();

            foreach (array_values($items) as $index => $item) {
                $offer->items()->create([
                    'name' => trim((string) $item['name']),
                    'unit' => isset($item['unit']) && trim((string) $item['unit']) !== ''
                        ? trim((string) $item['unit'])
                        : 'dona',
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'sort_order' => $index,
                ]);
            }

            $offer->load('items');
            $offer->recomputeTotal();
            $offer->update(['price_updated_at' => now()]);
        });

        $chat = $this->directChats->findForOffer($offer)
            ?? $this->directChats->openForOffer($agent, $offer->fresh());

        $this->directChats->postEvent(
            $chat,
            $agent,
            DirectChatMessage::TYPE_OFFER_PRICELIST_SENT,
            'Pricelist sent',
            [
                'order_id' => $order->id,
                'offer_id' => $offer->id,
                'total' => (string) $offer->fresh()->price,
                'item_count' => count($items),
            ],
        );

        try {
            $this->notifier->notifyOfferPricelistSent($offer->fresh(['order.client', 'agent', 'agentProfile']));
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->findForAgent($agent, $offer->fresh());
    }

    /**
     * Client picks a winning offer: it becomes accepted and the rest rejected.
     *
     * When the payment gateway is enabled the order moves to `awaiting_payment`
     * and the deal only activates once payment is confirmed (see
     * {@see activateDeal()}, called from the payment webhook). When the gateway
     * is off, the order activates immediately (offline MVP flow).
     */
    public function acceptOffer(User $client, Offer $offer): Offer
    {
        $order = $offer->order;

        abort_unless($order->client_id === $client->id, 404);

        if (! $offer->hasPrice()) {
            throw ValidationException::withMessages([
                'offer' => ['This response has no price yet and cannot be accepted.'],
            ]);
        }

        if (! in_array($order->status, [OrderStatus::New, OrderStatus::OffersSent], true)) {
            throw ValidationException::withMessages([
                'order' => ['This order is not awaiting a selection.'],
            ]);
        }

        $paymentEnabled = (bool) config('services.multicard.enabled');

        DB::transaction(function () use ($order, $offer, $paymentEnabled, $client): void {
            $order->offers()->whereKeyNot($offer->id)->update(['status' => OfferStatus::Rejected]);
            $offer->update(['status' => OfferStatus::Accepted]);

            // Accepting is explicit consent — reopen a previously ended order thread.
            $pair = $this->directChats->findPair($order->client_id, $offer->agent_id, $order->id);
            if ($pair !== null) {
                $this->directChats->clearBlock($pair);
                $this->directChats->postEvent(
                    $pair->fresh(),
                    $client,
                    DirectChatMessage::TYPE_OFFER_ACCEPTED,
                    'Offer accepted',
                    [
                        'order_id' => $order->id,
                        'offer_id' => $offer->id,
                        'price' => (string) $offer->price,
                    ],
                );
            }

            if ($paymentEnabled) {
                $order->update([
                    'status' => OrderStatus::AwaitingPayment,
                    'awaiting_payment_at' => now(),
                ]);
            } else {
                $this->activateInTransaction($order, $offer);
            }
        });

        if (! $paymentEnabled) {
            $this->notifyDeal($offer);
            $this->generateContract($offer);
        }

        return $offer->load(['agent', 'agentProfile.companyLogoFile']);
    }

    /**
     * Activate the deal for an already-accepted offer: move the order to
     * in_progress and open the client ↔ agent conversation. Invoked by the
     * payment webhook once a payment succeeds. Idempotent — a second webhook
     * (Multicard retries) is a no-op once in_progress.
     */
    public function activateDeal(Offer $offer): void
    {
        $order = $offer->order;

        if ($order->status === OrderStatus::InProgress) {
            return;
        }

        DB::transaction(function () use ($order, $offer): void {
            $this->activateInTransaction($order, $offer);
        });

        $this->notifyDeal($offer);
        $this->generateContract($offer);
    }

    private function activateInTransaction(Order $order, Offer $offer): void
    {
        $order->update(['status' => OrderStatus::InProgress]);

        // Open the client ↔ agent conversation for this deal.
        Chat::firstOrCreate(
            ['order_id' => $order->id],
            [
                'client_id' => $order->client_id,
                'agent_id' => $offer->agent_id,
                'agent_profile_id' => $offer->agent_profile_id,
            ],
        );

        // Queue the agent's advance payout out of escrow (gateway flow only;
        // no-op when payments are disabled). A manager releases it later.
        $this->payouts->planAdvance($order);
    }

    private function notifyDeal(Offer $offer): void
    {
        try {
            $this->notifier->notifyOfferAccepted($offer);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Generate the per-order contract once the deal is active. Best-effort — a
     * rendering hiccup must not fail the deal (the contract can be regenerated).
     */
    private function generateContract(Offer $offer): void
    {
        try {
            $offer->loadMissing('order');
            if ($offer->order !== null) {
                $this->contracts->generateForOrder($offer->order);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
