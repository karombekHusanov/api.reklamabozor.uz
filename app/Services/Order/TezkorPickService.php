<?php

namespace App\Services\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\TezkorBilling;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Pass\PassSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Tezkor `pay_on_pick`: otkliks are free, the client picks one agency and that
 * agency pays the per-order fee to take the job. Until it pays the request
 * stays open — the pick only reserves a payment window
 * (`orders.pick_expires_at`). Paying closes the request exactly like the
 * `pay_to_respond` "Kelishildi" ({@see OrderService::closeWithAgency()});
 * declining, withdrawing or letting the window run out clears the pick so the
 * client can choose again.
 */
class TezkorPickService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PassGate $gate,
        private readonly PassSettings $settings,
        private readonly OrderNotifier $notifier,
    ) {}

    /** Client picks one of the pending otkliks; the agency gets a window to pay. */
    public function pick(User $client, Order $order, int $offerId): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        $result = DB::transaction(function () use ($order, $offerId): array {
            $locked = $this->lockOrder($order);

            if (! $locked->isTezkor() || ! $locked->status->isOpenForOffers()) {
                throw ValidationException::withMessages([
                    'order' => ['Only an open Tezkor request can be closed as agreed.'],
                ]);
            }

            if ($locked->tezkorBilling() !== TezkorBilling::PayOnPick) {
                throw ValidationException::withMessages([
                    'order' => ['This request is closed with "Agreed" directly.'],
                ]);
            }

            if ($locked->hasActivePick()) {
                // Re-sending the same pick is harmless; switching is not —
                // the picked agency keeps its window.
                if ($locked->picked_offer_id === $offerId) {
                    return [$locked, null];
                }

                throw ValidationException::withMessages([
                    'offer_id' => ['Wait until the agency you picked answers or its time runs out.'],
                ]);
            }

            /** @var Offer|null $offer */
            $offer = $locked->offers()
                ->whereKey($offerId)
                ->where('status', OfferStatus::Pending)
                ->first();

            if ($offer === null) {
                throw ValidationException::withMessages([
                    'offer_id' => ['Pick one of the agencies that responded to this request.'],
                ]);
            }

            $locked->update([
                'picked_offer_id' => $offer->id,
                'picked_at' => now(),
                'pick_expires_at' => now()->addMinutes($this->settings->pickPayMinutes()),
            ]);

            return [$locked, $offer];
        });

        [$locked, $offer] = $result;

        if ($offer !== null) {
            try {
                $this->notifier->notifyTezkorPicked($locked, $offer, intdiv($this->gate->feeTiyin($locked), 100));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->orders->withClientRelations($order->fresh());
    }

    /**
     * The picked agency pays the fee and takes the job — the request closes.
     * A short balance is a 402 (the pick stays, the agent may top up and retry
     * within the window).
     */
    public function take(User $agent, Offer $offer): Offer
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        DB::transaction(function () use ($agent, $offer): void {
            $locked = $this->lockOrder($offer->order);
            $offer->refresh()->setRelation('order', $locked);

            if ($locked->picked_offer_id !== $offer->id || $offer->status !== OfferStatus::Pending) {
                throw ValidationException::withMessages([
                    'offer' => ['The client has not picked you for this request.'],
                ]);
            }

            if (! $locked->hasActivePick()) {
                throw ValidationException::withMessages([
                    'offer' => ['The time to take this request has run out.'],
                ]);
            }

            $this->gate->chargeTake($agent, $offer);
            $this->orders->closeWithAgency($locked, $offer);
        });

        $order = $offer->order->fresh();

        try {
            $this->notifier->notifyTezkorTaken($order);
        } catch (\Throwable $e) {
            report($e);
        }

        return $offer->fresh();
    }

    /** The picked agency turns the job down: its otklik is withdrawn, the client picks again. */
    public function decline(User $agent, Offer $offer): Offer
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        $order = DB::transaction(function () use ($offer): Order {
            $locked = $this->lockOrder($offer->order);

            if ($locked->picked_offer_id !== $offer->id || $offer->fresh()->status !== OfferStatus::Pending) {
                throw ValidationException::withMessages([
                    'offer' => ['The client has not picked you for this request.'],
                ]);
            }

            $this->clearPick($locked);
            $offer->update(['status' => OfferStatus::Withdrawn]);

            return $locked;
        });

        $this->notifyLapsed($order, $offer, 'declined');

        return $offer->fresh();
    }

    /**
     * The agent withdrew an otklik the client had picked — drop the pick so
     * the client can choose again. Called by {@see OfferService::withdraw()}.
     */
    public function releaseWithdrawn(Offer $offer): void
    {
        $order = DB::transaction(function () use ($offer): ?Order {
            $locked = $this->lockOrder($offer->order);

            if ($locked->picked_offer_id !== $offer->id) {
                return null;
            }

            $this->clearPick($locked);

            return $locked;
        });

        if ($order !== null) {
            $this->notifyLapsed($order, $offer, 'declined');
        }
    }

    /** Scheduler: clear picks whose payment window ran out. Returns how many lapsed. */
    public function expireLapsed(): int
    {
        $count = 0;

        Order::query()
            ->whereNotNull('picked_offer_id')
            ->whereNotNull('pick_expires_at')
            ->where('pick_expires_at', '<=', now())
            ->whereIn('status', $this->openStatuses())
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                $lapsed = DB::transaction(function () use ($id): ?array {
                    /** @var Order $locked */
                    $locked = Order::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                    if ($locked->picked_offer_id === null || $locked->hasActivePick() || ! $locked->status->isOpenForOffers()) {
                        return null;
                    }

                    $offer = Offer::query()->find($locked->picked_offer_id);
                    $this->clearPick($locked);

                    return $offer !== null ? [$locked, $offer] : null;
                });

                if ($lapsed !== null) {
                    $count++;
                    $this->notifyLapsed($lapsed[0], $lapsed[1], 'expired');
                }
            });

        return $count;
    }

    private function lockOrder(Order $order): Order
    {
        /** @var Order */
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function clearPick(Order $order): void
    {
        Log::info('tezkor.pick.cleared', ['order_id' => $order->id, 'offer_id' => $order->picked_offer_id]);

        $order->update([
            'picked_offer_id' => null,
            'picked_at' => null,
            'pick_expires_at' => null,
        ]);
    }

    private function notifyLapsed(Order $order, Offer $offer, string $reason): void
    {
        try {
            $this->notifier->notifyTezkorPickLapsed($order, $offer, $reason);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> */
    private function openStatuses(): array
    {
        return array_map(fn (OrderStatus $status) => $status->value, OrderStatus::openForOffers());
    }
}
