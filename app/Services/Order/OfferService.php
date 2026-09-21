<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\ReviewDirection;
use App\Models\Chat;
use App\Models\ContractAcceptance;
use App\Models\DirectChatMessage;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderView;
use App\Models\User;
use App\Services\Chat\DirectChatService;
use App\Services\Fiscal\FiscalService;
use App\Services\Payout\PayoutService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly PayoutService $payouts,
        private readonly DirectChatService $directChats,
        private readonly OrderContractService $contracts,
        private readonly FiscalService $fiscal,
        private readonly PassGate $passes,
    ) {}

    /**
     * Orders an approved agent may bid on: open for offers, and either in one
     * of the agent's categories or a broadcast order ("Other" / empty category
     * with no approved providers). Each order carries the agent's own offer
     * (if any).
     *
     * @param  int|null  $orderId  When set, return at most that one opportunity.
     * @param  OrderRoute|null  $route  When set, only orders on that route.
     * @return Collection<int, Order>
     */
    public function availableForAgent(User $agent, ?int $orderId = null, ?OrderRoute $route = null): Collection
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
                    // No category picked → broadcast, open to everyone approved.
                    ->orWhereNull('category_id')
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
            ->when($route !== null, fn ($q) => $q->where('route', $route->value))
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

        // Directed order: only the addressed agency may respond. The feed query
        // ({@see availableForAgent}) already hides it from others, but the submit
        // endpoint is reachable directly, so enforce the restriction here too.
        if ($order->target_agent_id !== null && $order->target_agent_id !== $agent->id) {
            throw ValidationException::withMessages([
                'order' => ['This order is addressed to a specific agency.'],
            ]);
        }

        // 1 user = 1 profile: the single approved profile responds, eligible when
        // it lists this category or the order is a broadcast ("Other" / empty
        // category). Both resolvers return that one profile — null means the
        // profile does not serve this order.
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

        if ($order->isTezkor() && isset($data['price'])) {
            throw ValidationException::withMessages([
                'price' => ['Tezkor requests take an interest only — agree the price with the client directly.'],
            ]);
        }

        // Tezkor: the claim is the exclusive slot. Lock the order row so two
        // agents tapping at once cannot both win (the loser gets 409).
        $offer = DB::transaction(function () use ($agent, $order, $profile, $data): Offer {
            if ($order->isTezkor()) {
                /** @var Order $locked */
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if (! $locked->status->isOpenForOffers()) {
                    throw ValidationException::withMessages([
                        'order' => ['This order is no longer accepting offers.'],
                    ]);
                }

                if ($locked->isClaimed()) {
                    throw new HttpResponseException(
                        ApiResponse::error('This request is already taken by another agent.', 409),
                    );
                }

                $this->passes->assertCanClaim($agent, $locked);
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

            if ($order->isTezkor()) {
                $order->update([
                    'claimed_agent_id' => $agent->id,
                    'claimed_at' => now(),
                    'status' => OrderStatus::OffersSent,
                    // A fresh claim gets its own "still waiting?" nudge.
                    'stale_reminder_sent_at' => null,
                ]);
            } elseif ($order->status === OrderStatus::New) {
                $order->update(['status' => OrderStatus::OffersSent]);
            }

            return $offer;
        });

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
            'contractAcceptances',
            'order.category',
            'order.region',
            'order.district',
            'order.hashtags',
            'order.client.avatarFile',
            'order.contract.pdfFile',
            'order.documents.pdfFile',
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
     * Agent pulls back their own pending offer/interest. A distinct status
     * from Rejected (the client picking someone else) so stats and the
     * "you lost this deal" notification don't misattribute an agent's own
     * change of mind. The same (order_id, agent_id) row stays unique — an
     * agent who withdraws cannot re-offer on this order (same rule that
     * already applies to a rejected offer).
     */
    public function withdraw(User $agent, Offer $offer): Offer
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        if (! $offer->canWithdraw()) {
            throw ValidationException::withMessages([
                'offer' => ['This offer can no longer be withdrawn.'],
            ]);
        }

        $offer->update(['status' => OfferStatus::Withdrawn]);

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
        $offer->order?->assertTender();

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
     * The contract the agent is about to accept, built from the pricelist rows
     * they are composing (nothing is stored yet).
     *
     * @param  array<int, array{name: string, unit?: string|null, quantity: float|int|string, unit_price: float|int|string}>  $items
     * @return array<string, mixed>
     */
    public function previewContractForAgent(User $agent, Offer $offer, array $items, ?int $deadlineDays = null): array
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        $offer->loadMissing('order')->order?->assertTender();

        return $this->contracts->document($offer, array_values($items), $deadlineDays);
    }

    /**
     * The contract the client is about to accept, built from the offer's stored
     * pricelist. Only the order owner may read it, and only for a priced offer.
     *
     * @return array<string, mixed>
     */
    public function previewContractForClient(User $client, Offer $offer): array
    {
        $offer->loadMissing('order');

        abort_unless($offer->order?->client_id === $client->id, 404);

        $offer->order->assertTender();

        if (! $offer->hasPrice()) {
            throw ValidationException::withMessages([
                'offer' => ['This response has no price yet — there is no contract to accept.'],
            ]);
        }

        return $this->contracts->document($offer);
    }

    /**
     * Agent sends (or replaces) the pricelist on their pending offer — the
     * priced step after negotiating in the order chat. Sending is also the
     * agent's acceptance of the per-order contract built from those lines, so
     * the consent is logged before the offer reaches the client. Replaces every
     * line, recomputes the cached total (interest → priced), posts an event into
     * the order-scoped thread, and notifies the client.
     *
     * @param  array<int, array{name: string, unit?: string|null, quantity: float|int|string, unit_price: float|int|string}>  $items
     */
    public function setPricelist(
        User $agent,
        Offer $offer,
        array $items,
        ?int $deadlineDays = null,
        ?Request $request = null,
    ): Offer {
        abort_unless($offer->agent_id === $agent->id, 404);

        $offer->loadMissing('order');
        $order = $offer->order;

        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => ['This order is no longer available.'],
            ]);
        }

        $order->assertTender();

        if ($offer->status !== OfferStatus::Pending || ! $order->status->isOpenForOffers()) {
            throw ValidationException::withMessages([
                'offer' => ['This offer can no longer be priced.'],
            ]);
        }

        // Fiscal classifier for this order's category — frozen on every row so
        // the receipt (and a later partial refund) can be built from the offer.
        $fiscal = $this->fiscal->fieldsForOrder($order);

        DB::transaction(function () use ($agent, $offer, $items, $deadlineDays, $request, $fiscal): void {
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
                    ...$fiscal,
                ]);
            }

            $offer->load('items');
            $offer->recomputeTotal();
            $offer->update([
                'price_updated_at' => now(),
                'deadline_days' => $deadlineDays,
            ]);

            // The agent's "accept" on the contract drawer — logged against the
            // exact document (parties, lines, clause text) they were shown.
            $this->contracts->recordAcceptance(
                $offer,
                $agent,
                ContractAcceptance::PARTY_AGENT,
                $this->contracts->document($offer->fresh()->load('items')),
                $request,
            );
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
     * Accepting the contract activates the deal immediately — work starts while
     * the money runs on its own track ({@see Order::$payment_state}). With the
     * gateway enabled the order is activated as `unpaid` with a payment due
     * date, and the client then settles it however they like (in-app checkout,
     * invoice link/QR, cash or bank transfer). With the gateway off nothing is
     * collected (`not_required`), as in the offline MVP flow.
     */
    public function acceptOffer(
        User $client,
        Offer $offer,
        ?string $expectedContractHash = null,
        ?Request $request = null,
    ): Offer {
        $order = $offer->order;

        abort_unless($order->client_id === $client->id, 404);

        $order->assertTender();

        if (! $offer->hasPrice()) {
            throw ValidationException::withMessages([
                'offer' => ['This response has no price yet and cannot be accepted.'],
            ]);
        }

        // The contract the client is accepting right now. When the drawer sent
        // back the hash it read, refuse a stale accept (agent revised meanwhile).
        $document = $this->contracts->document($offer);

        if ($expectedContractHash !== null && ! hash_equals($document['hash'], $expectedContractHash)) {
            throw ValidationException::withMessages([
                'accept_contract' => ['The contract changed — reopen it and accept the current version.'],
            ]);
        }

        if (! in_array($order->status, [OrderStatus::New, OrderStatus::OffersSent], true)) {
            throw ValidationException::withMessages([
                'order' => ['This order is not awaiting a selection.'],
            ]);
        }

        $paymentEnabled = (bool) config('payments.enabled');

        DB::transaction(function () use ($order, $offer, $paymentEnabled, $client, $document, $request): void {
            $order->offers()->whereKeyNot($offer->id)->update(['status' => OfferStatus::Rejected]);
            $offer->update(['status' => OfferStatus::Accepted]);

            // Aksept: the client's tap on the contract drawer is the binding
            // moment — log it before the deal (or the checkout) starts.
            $this->contracts->recordAcceptance(
                $offer,
                $client,
                ContractAcceptance::PARTY_CLIENT,
                $document,
                $request,
            );

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

            $this->activateInTransaction($order, $offer);

            $order->update($paymentEnabled ? [
                'payment_state' => OrderPaymentState::Unpaid,
                'payment_due_at' => now()->addDays($this->paymentDueDays()),
                'awaiting_payment_at' => now(),
            ] : [
                'payment_state' => OrderPaymentState::NotRequired,
            ]);
        });

        $this->notifyDeal($offer);
        $this->generateContract($offer);

        return $offer->load(['agent', 'agentProfile.companyLogoFile']);
    }

    /**
     * Activate the deal for an already-accepted offer: move the order to
     * in_progress and open the client ↔ agent conversation. Used by
     * {@see acceptOffer()} and, for legacy orders parked in `awaiting_payment`,
     * by the payment webhook. Idempotent — a repeat call is a no-op once
     * in_progress.
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
        $order->update([
            'status' => OrderStatus::InProgress,
            // Frozen start of the deal — amendment windows are measured from it.
            'activated_at' => $order->activated_at ?? now(),
        ]);

        // Open the client ↔ agent conversation for this deal.
        Chat::firstOrCreate(
            ['order_id' => $order->id],
            [
                'client_id' => $order->client_id,
                'agent_id' => $offer->agent_id,
                'agent_profile_id' => $offer->agent_profile_id,
            ],
        );

        // Payouts follow the money, not the activation: the advance is planned
        // when the payment actually settles (PaymentService::onOrderPaid).
    }

    /** Grace period, in days, between activation and the payment due date. */
    private function paymentDueDays(): int
    {
        return max(1, (int) config('payments.payment_due_days', 3));
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
