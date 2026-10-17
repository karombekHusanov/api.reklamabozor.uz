<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\ReviewDirection;
use App\Models\Category;
use App\Models\Chat;
use App\Models\ContractAcceptance;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderView;
use App\Models\User;
use App\Services\Chat\DirectChatService;
use App\Services\Fiscal\FiscalService;
use App\Services\Payout\PayoutService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /** Feed page size for {@see availableForAgent()}. */
    public const FEED_PER_PAGE = 20;

    /**
     * Orders an approved agent may bid on: open for offers, and either in one
     * of the agent's categories or a broadcast order ("Other" / empty category
     * with no approved providers), newest first, paginated. Each order carries
     * the agent's own offer (if any). Browsing the feed never writes — views
     * are recorded when the agent opens an order ({@see findAvailableForAgent()}).
     *
     * @param  OrderRoute|null  $route  When set, only orders on that route.
     * @return LengthAwarePaginator<int, Order>
     */
    public function availableForAgent(User $agent, ?OrderRoute $route = null, int $perPage = self::FEED_PER_PAGE): LengthAwarePaginator
    {
        $query = $this->feedQuery($agent, $route);

        if ($query === null) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $paginator = $query->latest()->paginate(max(1, min($perPage, 50)));

        Order::hydrateAttachmentFiles($paginator->getCollection());
        $this->hydrateOwnOfferChats($agent, $paginator->getCollection());

        return $paginator;
    }

    /**
     * Attach the agent's order thread to each of their own offers in the feed
     * (`my_offer.chat_id`) with one query instead of a lookup per offer.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function hydrateOwnOfferChats(User $agent, Collection $orders): void
    {
        $offers = $orders->flatMap(fn (Order $order) => $order->offers->each(
            fn (Offer $offer) => $offer->setRelation('order', $order),
        ));

        if ($offers->isEmpty()) {
            return;
        }

        $chats = DirectChat::query()
            ->where('agent_id', $agent->id)
            ->whereIn('order_id', $offers->pluck('order_id')->unique())
            ->get()
            ->keyBy('order_id');

        $offers->each(fn (Offer $offer) => $offer->setRelation('directChat', $chats->get($offer->order_id)));
    }

    /**
     * Single open opportunity the agent may bid on (404 if outside their feed).
     * Opening it counts as a view (distinct per agent).
     */
    public function findAvailableForAgent(User $agent, Order $order): Order
    {
        $found = $this->feedQuery($agent)?->whereKey($order->id)->first();

        abort_unless($found !== null, 404);

        $this->recordViews($agent, new Collection([$found]));
        Order::hydrateAttachmentFiles($found);

        return $found;
    }

    /**
     * The agent's opportunity feed as a query (null when the agent has no
     * approved profile): eligibility, route and eager loads, no ordering.
     *
     * @return Builder<Order>|null
     */
    private function feedQuery(User $agent, ?OrderRoute $route = null): ?Builder
    {
        // Categories served by the agent's approved profile (1 user = 1 profile).
        $profile = $agent->profile()
            ->where('status', AgentProfileStatus::Approved)
            ->with('categories')
            ->first();

        if ($profile === null) {
            return null;
        }

        // Tender-ineligible providers (individuals, or legal entities without
        // complete bank requisites) never see Tender orders — force the feed to
        // Tezkor even if Tender was explicitly requested.
        if (! $profile->canBidOnTender()) {
            $route = OrderRoute::Tezkor;
        }

        // Served by subtree: every listed category plus all its descendants,
        // plus categories nobody approved serves yet (broadcast to everyone).
        $categoryIds = array_values(array_unique(array_merge(
            Category::descendantIdsOf($profile->categories->pluck('id')->all()),
            Category::unservedIds(),
        )));

        return Order::query()
            ->where(function ($query) use ($categoryIds): void {
                $query->whereIn('category_id', $categoryIds)
                    // No category picked → broadcast, open to everyone approved.
                    ->orWhereNull('category_id')
                    ->orWhereHas('category', function ($categoryQuery): void {
                        // Catch-all "Boshqa" always open to every approved provider.
                        $categoryQuery->where('is_other', true);
                    });
            })
            // Broadcast orders (no target) are open to all; a directed order only
            // ever shows to the single agency it was addressed to.
            ->where(fn ($q) => $q->whereNull('target_agent_id')->orWhere('target_agent_id', $agent->id))
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::openForOffers()))
            ->when($route !== null, fn ($q) => $q->where('route', $route->value))
            ->withCount(['views', 'offers'])
            ->with([
                'category',
                'region',
                'district',
                'hashtags',
                'client.avatarFile',
                'offers' => fn ($query) => $query->where('agent_id', $agent->id)->with('items'),
            ]);
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

        // Work gate: an approved executor starts working only once the profile
        // lists its service categories and its location on the map.
        if (! $profile->isReadyToWork()) {
            throw ValidationException::withMessages([
                'profile' => ['Add your service categories and map location to your profile before responding to requests.'],
            ]);
        }

        // Tender gate: only a legal entity with complete bank requisites may bid
        // on a Tender order (payouts run over bank transfer). Individuals are
        // Tezkor-only; a legal entity missing requisites gets a distinct nudge.
        if ($order->isTender() && ! $profile->canBidOnTender()) {
            throw ValidationException::withMessages([
                'order' => [$profile->isIndividual()
                    ? 'Individual executors can respond to Tezkor requests only.'
                    : 'Complete your bank requisites before responding to Tender orders.'],
            ]);
        }

        if ($order->isTezkor() && isset($data['price'])) {
            throw ValidationException::withMessages([
                'price' => ['Tezkor requests take an interest only — agree the price with the client directly.'],
            ]);
        }

        // Any paying agent may respond — Tezkor and Tender alike. There is no
        // exclusive slot: the client reads the otkliks and picks the agency.
        $offer = DB::transaction(function () use ($agent, $order, $profile, $data): Offer {
            // The open-for-offers check runs under the order row lock, in the
            // same transaction as the fee: a request being closed ("Kelishildi",
            // cancel, accept) at this very moment cannot take the agent's money
            // for an otklik that lands on a closed order.
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpenForOffers()) {
                throw ValidationException::withMessages([
                    'order' => ['This order is no longer accepting offers.'],
                ]);
            }

            if ($locked->offers()->where('agent_id', $agent->id)->exists()) {
                throw ValidationException::withMessages([
                    'order' => ['You have already sent an offer for this order.'],
                ]);
            }

            // Per-order fee on the otklik (Tender, Tezkor pay_to_respond);
            // free under Tezkor pay_on_pick — the picked agent pays on take.
            $fee = $this->passes->chargeOtklik($agent, $locked);

            /** @var Offer $offer */
            $offer = $locked->offers()->create([
                'agent_id' => $agent->id,
                'agent_profile_id' => $profile->id,
                'price' => $data['price'] ?? null,
                'comment' => $data['comment'] ?? null,
                'status' => OfferStatus::Pending,
                'fee_tiyin' => $fee > 0 ? $fee : null,
                'fee_paid_at' => $fee > 0 ? now() : null,
            ]);

            if ($locked->status === OrderStatus::New) {
                $locked->update(['status' => OrderStatus::OffersSent]);
            }

            // Keep the caller's instance in step with what was committed.
            $order->setRawAttributes($locked->getAttributes(), true);

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

        // Tezkor pay_on_pick: withdrawing the picked otklik frees the client to
        // pick again. Resolved lazily — TezkorPickService → OrderService →
        // PaymentService → OfferService would be a constructor cycle.
        if ($offer->order?->picked_offer_id === $offer->id) {
            app(TezkorPickService::class)->releaseWithdrawn($offer);
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

        $this->assertAwaitingSelection($order);

        $paymentEnabled = (bool) config('payments.enabled');

        DB::transaction(function () use ($order, $offer, $paymentEnabled, $client, $document, $request): void {
            // The whole transition runs under the order row lock: two accepts
            // fired in parallel (two tabs, two offers) serialise here, and the
            // second one sees the order already in progress and is refused —
            // so one deal, one chat, one accepted offer.
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertAwaitingSelection($locked);

            // Work on the locked row from here on; the caller's instance (and
            // the offer's loaded relation) follow it.
            $order->setRawAttributes($locked->getAttributes(), true);
            $offer->setRelation('order', $order);

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

    private function assertAwaitingSelection(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::New, OrderStatus::OffersSent], true)) {
            throw ValidationException::withMessages([
                'order' => ['This order is not awaiting a selection.'],
            ]);
        }
    }

    /**
     * Activate the deal for an already-accepted offer: move the order to
     * in_progress and open the client ↔ agent conversation. Used by
     * {@see acceptOffer()}. Idempotent — a repeat call is a no-op once
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
