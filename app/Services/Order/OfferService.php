<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Chat;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderView;
use App\Models\User;
use App\Services\Payout\PayoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly PayoutService $payouts,
    ) {}

    /**
     * Orders an approved agent may bid on: open for offers, in one of the
     * agent's categories. Each order carries the agent's own offer (if any).
     *
     * @return Collection<int, Order>
     */
    public function availableForAgent(User $agent): Collection
    {
        // Categories served by any of the agent's approved provider profiles.
        $profiles = $agent->providerProfiles()
            ->where('status', AgentProfileStatus::Approved)
            ->with('categories')
            ->get();

        if ($profiles->isEmpty()) {
            return new Collection;
        }

        $categoryIds = $profiles
            ->flatMap(fn (AgentProfile $profile) => $profile->categories->pluck('id'))
            ->unique()
            ->values();

        $orders = Order::query()
            ->whereIn('category_id', $categoryIds)
            // Broadcast orders (no target) are open to all; a directed order only
            // ever shows to the single agency it was addressed to.
            ->where(fn ($q) => $q->whereNull('target_agent_id')->orWhere('target_agent_id', $agent->id))
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::openForOffers()))
            ->withCount(['views', 'offers'])
            ->with([
                'category',
                'client',
                'offers' => fn ($query) => $query->where('agent_id', $agent->id),
            ])
            ->latest()
            ->get();

        $this->recordViews($agent, $orders);
        Order::hydrateAttachmentFiles($orders);

        return $orders;
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
        // The approved profile whose categories include this order's — i.e. the
        // one bidding. Null means no eligible profile (unapproved or off-category).
        $profile = $agent->providerProfileForCategory($order->category_id);

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
            'price' => $data['price'],
            'comment' => $data['comment'],
            'status' => OfferStatus::Pending,
        ]);

        if ($order->status === OrderStatus::New) {
            $order->update(['status' => OrderStatus::OffersSent]);
        }

        try {
            $this->notifier->notifyNewOffer($offer);
        } catch (\Throwable $e) {
            report($e);
        }

        return $offer->load(['agent', 'agentProfile.companyLogoFile']);
    }

    /**
     * @return Collection<int, Offer>
     */
    public function listForAgent(User $agent): Collection
    {
        return Offer::query()
            ->where('agent_id', $agent->id)
            ->with('order.category')
            ->latest()
            ->get();
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

        if (! in_array($order->status, [OrderStatus::New, OrderStatus::OffersSent], true)) {
            throw ValidationException::withMessages([
                'order' => ['This order is not awaiting a selection.'],
            ]);
        }

        $paymentEnabled = (bool) config('services.multicard.enabled');

        DB::transaction(function () use ($order, $offer, $paymentEnabled): void {
            $order->offers()->whereKeyNot($offer->id)->update(['status' => OfferStatus::Rejected]);
            $offer->update(['status' => OfferStatus::Accepted]);

            if ($paymentEnabled) {
                $order->update(['status' => OrderStatus::AwaitingPayment]);
            } else {
                $this->activateInTransaction($order, $offer);
            }
        });

        if (! $paymentEnabled) {
            $this->notifyDeal($offer);
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
}
