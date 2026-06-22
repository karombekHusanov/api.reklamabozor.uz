<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferService
{
    /**
     * Orders an approved agent may bid on: open for offers, in one of the
     * agent's categories. Each order carries the agent's own offer (if any).
     *
     * @return Collection<int, Order>
     */
    public function availableForAgent(User $agent): Collection
    {
        $profile = $agent->agentProfile;

        if ($profile === null || $profile->status !== AgentProfileStatus::Approved) {
            return new Collection;
        }

        $categoryIds = $profile->categories()->pluck('categories.id');

        return Order::query()
            ->whereIn('category_id', $categoryIds)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, OrderStatus::openForOffers()))
            ->with([
                'category',
                'tzFile',
                'client',
                'offers' => fn ($query) => $query->where('agent_id', $agent->id),
            ])
            ->latest()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitOffer(User $agent, Order $order, array $data): Offer
    {
        $profile = $agent->agentProfile;

        if ($profile === null || $profile->status !== AgentProfileStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['Only approved agents can send offers.'],
            ]);
        }

        if (! $profile->categories()->where('categories.id', $order->category_id)->exists()) {
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
            'price' => $data['price'],
            'comment' => $data['comment'],
            'status' => OfferStatus::Pending,
        ]);

        if ($order->status === OrderStatus::New) {
            $order->update(['status' => OrderStatus::OffersSent]);
        }

        return $offer->load('agent.agentProfile.companyLogoFile');
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
     * Client picks a winning offer: it becomes accepted, the rest rejected,
     * and the order moves to "client selected" (awaiting admin activation).
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

        DB::transaction(function () use ($order, $offer): void {
            $order->offers()->whereKeyNot($offer->id)->update(['status' => OfferStatus::Rejected]);
            $offer->update(['status' => OfferStatus::Accepted]);
            $order->update(['status' => OrderStatus::ClientSelected]);
        });

        return $offer->load('agent.agentProfile.companyLogoFile');
    }
}
