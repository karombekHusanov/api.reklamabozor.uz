<?php

namespace App\Services\Payout;

use App\Enums\OfferStatus;
use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;

/**
 * Splits an order's escrow into agent payouts. The client pays 100% up front;
 * we owe the agent the deal price minus the platform commission, released as
 * an advance (deal start) + final (completion). Percentages are configurable
 * (defaults, not hardcoded) and a manager can override any amount at release.
 *
 * v1 releases are manual (a manager marks them paid). The `release()` seam is
 * where the automated Multicard credit flow plugs in later.
 */
class PayoutService
{
    /**
     * Amount owed to the agent after commission, in tiyin.
     */
    public function agentNet(int $totalTiyin): int
    {
        $commissionPercent = max(0.0, (float) config('services.multicard.commission_percent', 0));
        $commission = (int) floor($totalTiyin * $commissionPercent / 100);

        return max(0, $totalTiyin - $commission);
    }

    /**
     * Default advance slice of the net, in tiyin (percentage is configurable).
     */
    public function advanceAmount(int $net): int
    {
        $percent = min(100.0, max(0.0, (float) config('services.multicard.payout_advance_percent', 40)));

        return (int) floor($net * $percent / 100);
    }

    /**
     * Create the advance payout when a deal activates. No-op when the gateway
     * is off (no escrow was collected) or an advance already exists.
     */
    public function planAdvance(Order $order): ?Payout
    {
        if (! $this->escrowFunded()) {
            return null;
        }

        if ($order->payouts()->where('tranche', PayoutTranche::Advance)->exists()) {
            return null;
        }

        $offer = $this->acceptedOffer($order);

        // No accepted offer, or no provider profile to attribute the payout to
        // (real bids always carry one) — nothing to release.
        if ($offer === null || $offer->agent_profile_id === null) {
            return null;
        }

        $net = $this->agentNet($this->dealAmount($offer));

        return $this->createPayout($order, $offer, PayoutTranche::Advance, $this->advanceAmount($net));
    }

    /**
     * Create the final payout when the order completes: the remaining net after
     * whatever was already planned (so a manager-overridden advance is honoured).
     * No-op when the gateway is off or a final payout already exists.
     */
    public function planFinal(Order $order): ?Payout
    {
        if (! $this->escrowFunded()) {
            return null;
        }

        if ($order->payouts()->where('tranche', PayoutTranche::Final)->exists()) {
            return null;
        }

        $offer = $this->acceptedOffer($order);

        // No accepted offer, or no provider profile to attribute the payout to
        // (real bids always carry one) — nothing to release.
        if ($offer === null || $offer->agent_profile_id === null) {
            return null;
        }

        $net = $this->agentNet($this->dealAmount($offer));

        $alreadyPlanned = (int) $order->payouts()
            ->where('status', '!=', PayoutStatus::Cancelled->value)
            ->sum('amount');

        $amount = max(0, $net - $alreadyPlanned);

        return $this->createPayout($order, $offer, PayoutTranche::Final, $amount);
    }

    /**
     * Release a payout to the agent. v1: a manager marks it paid manually
     * (optionally overriding the amount and recording a bank reference). The
     * automated Multicard credit flow will branch on `method` here later.
     *
     * @param  array{amount?: int|null, reference?: string|null, method?: string|null}  $data
     */
    public function release(Payout $payout, User $manager, array $data = []): Payout
    {
        if (array_key_exists('amount', $data) && $data['amount'] !== null) {
            $payout->amount = max(0, (int) $data['amount']);
        }

        $payout->method = $data['method'] ?? 'manual';
        $payout->reference = $data['reference'] ?? $payout->reference;
        $payout->released_by = $manager->id;
        $payout->status = PayoutStatus::Paid;
        $payout->paid_at = now();
        $payout->save();

        return $payout->refresh();
    }

    private function escrowFunded(): bool
    {
        return (bool) config('services.multicard.enabled');
    }

    private function acceptedOffer(Order $order): ?Offer
    {
        return $order->offers()->where('status', OfferStatus::Accepted)->first();
    }

    /** Deal amount = accepted offer price, in tiyin. */
    private function dealAmount(Offer $offer): int
    {
        return (int) round(((float) $offer->price) * 100);
    }

    private function createPayout(Order $order, Offer $offer, PayoutTranche $tranche, int $amount): Payout
    {
        return $order->payouts()->create([
            'agent_profile_id' => $offer->agent_profile_id,
            'agent_id' => $offer->agent_id,
            'tranche' => $tranche,
            'amount' => $amount,
            'currency' => 'UZS',
            'status' => PayoutStatus::Pending,
        ]);
    }
}
