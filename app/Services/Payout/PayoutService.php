<?php

namespace App\Services\Payout;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderProblemState;
use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Splits an order's escrow into agent payouts. The client pays 100% up front;
 * we owe the agent the deal price minus the platform commission, released as
 * an advance (deal start) + final (completion). Percentages are configurable
 * (defaults, not hardcoded) and a manager can override any amount at release.
 *
 * Money leaves the platform as a bank transfer to the agent's KYC account
 * (`payouts.channel`): the gateway has no account-payout API, so a manager
 * executes the transfer and records its reference here.
 */
class PayoutService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * Amount owed to the agent after commission, in tiyin.
     */
    public function agentNet(int $totalTiyin): int
    {
        $commissionPercent = max(0.0, (float) config('payments.commission_percent', 0));
        $commission = (int) floor($totalTiyin * $commissionPercent / 100);

        return max(0, $totalTiyin - $commission);
    }

    /**
     * Default advance slice of the net, in tiyin (percentage is configurable).
     */
    public function advanceAmount(int $net): int
    {
        $percent = min(100.0, max(0.0, (float) config('payments.advance_percent', 40)));

        return (int) floor($net * $percent / 100);
    }

    /**
     * Create the advance payout when a deal activates. No-op when the gateway
     * is off (no escrow was collected) or an advance already exists.
     */
    public function planAdvance(Order $order): ?Payout
    {
        if ($order->isTezkor() || ! $this->orderIsPaid($order)) {
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
        if ($order->isTezkor() || ! $this->orderIsPaid($order)) {
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
     * Void unpaid payouts for an order (e.g. after a gateway refund). Pending
     * and in-flight (processing) rows become cancelled; already-paid amounts
     * are left alone — ops must recover those manually. Returns how many rows
     * were cancelled and how much tiyin was already paid out.
     *
     * @return array{cancelled: int, paid_tiyin: int}
     */
    public function cancelUnpaidForOrder(Order $order): array
    {
        $cancelled = $order->payouts()
            ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])
            ->update([
                'status' => PayoutStatus::Cancelled->value,
            ]);

        $paidTiyin = (int) $order->payouts()
            ->where('status', PayoutStatus::Paid->value)
            ->sum('amount');

        return ['cancelled' => $cancelled, 'paid_tiyin' => $paidTiyin];
    }

    /**
     * Release a payout to the agent: a manager transfers the money to the
     * agent's bank account and marks it paid here. The payment-order reference
     * is required on the bank channel (it is the transfer's only audit trail);
     * the amount may be overridden.
     *
     * @param  array{amount?: int|null, reference?: string|null, method?: string|null}  $data
     */
    public function release(Payout $payout, User $manager, array $data = []): Payout
    {
        $this->assertReleasable($payout);

        $method = $data['method'] ?? (string) config('payouts.channel', 'bank');
        $reference = isset($data['reference']) ? trim((string) $data['reference']) : '';

        if ($method === 'bank') {
            $this->assertBankRequisites($payout);

            // A bank transfer is only auditable through its payment order.
            if ($reference === '') {
                throw ValidationException::withMessages([
                    'reference' => ['Enter the payment-order number of the bank transfer.'],
                ]);
            }
        }

        if (array_key_exists('amount', $data) && $data['amount'] !== null) {
            $payout->amount = max(0, (int) $data['amount']);
        }

        $payout->method = $method;
        $payout->reference = $reference !== '' ? $reference : $payout->reference;
        $payout->released_by = $manager->id;
        $payout->status = PayoutStatus::Paid;
        $payout->paid_at = now();
        $payout->save();

        $payout->refresh();

        // The transfer happens in the bank, off-platform — this is the only
        // moment the agent learns their money is on the way.
        $this->notifier->notifyPayoutReleased($payout);

        return $payout;
    }

    /**
     * Money may only leave the platform once the client's cooling-off window has
     * closed — until then they can still cancel the deal and get the payment
     * back, and a released payout would have nothing to reverse. It is also
     * held while the order has an open problem report (a quality dispute past
     * its correction window, or an agent who never started) — a manager must
     * resolve that report first, so money never leaves while a complaint is
     * still under review.
     */
    private function assertReleasable(Payout $payout): void
    {
        $order = $payout->order()->first();

        if ($order === null) {
            return;
        }

        if ($order->problem_state === OrderProblemState::Flagged) {
            throw ValidationException::withMessages([
                'payout' => ['This order has an open problem report — resolve it in Problem Orders first.'],
            ]);
        }

        if (! $order->payoutsLocked()) {
            return;
        }

        $minutes = max(1, (int) now()->diffInMinutes($order->payoutsUnlockAt(), absolute: true));

        throw ValidationException::withMessages([
            'payout' => ["The client can still cancel this order — payouts unlock in {$minutes} min."],
        ]);
    }

    /**
     * A bank transfer needs somewhere to land: refuse to mark a payout paid
     * while the agent's KYC requisites are incomplete, so the money is never
     * recorded as sent against a blank account.
     */
    private function assertBankRequisites(Payout $payout): void
    {
        $profile = $payout->agentProfile()->first();

        if ($profile !== null && $profile->hasBankRequisites()) {
            return;
        }

        throw ValidationException::withMessages([
            'payout' => ["The agent's bank requisites are incomplete — the transfer cannot be recorded."],
        ]);
    }

    /**
     * The agent's earnings summary, in tiyin. `available` is what the agent may
     * withdraw right now (pending payouts); `processing` is a withdrawal in
     * flight; `paid` is already transferred. Cancelled payouts are excluded.
     *
     * @return array{available: int, processing: int, paid: int, total: int}
     */
    public function balanceFor(User $agent): array
    {
        $sums = $agent->payouts()
            ->where('status', '!=', PayoutStatus::Cancelled->value)
            ->selectRaw('status, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $available = (int) ($sums[PayoutStatus::Pending->value] ?? 0);
        $processing = (int) ($sums[PayoutStatus::Processing->value] ?? 0);
        $paid = (int) ($sums[PayoutStatus::Paid->value] ?? 0);

        return [
            'available' => $available,
            'processing' => $processing,
            'paid' => $paid,
            'total' => $available + $processing + $paid,
        ];
    }

    /**
     * Payouts follow the money: a tranche is only planned once the client's
     * payment actually settled (gateway, cash or bank transfer). Orders on the
     * offline flow — where the platform collects nothing — never plan payouts.
     */
    private function orderIsPaid(Order $order): bool
    {
        // The state alone is not enough once amendments can change the amount:
        // the ledger has the last word.
        return $order->payment_state === OrderPaymentState::Paid
            && $order->outstandingTiyin() <= 0;
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

    /**
     * The unique (order_id, tranche) index makes a concurrent duplicate fail —
     * that just means another request already planned it, so return null.
     */
    private function createPayout(Order $order, Offer $offer, PayoutTranche $tranche, int $amount): ?Payout
    {
        try {
            return $order->payouts()->create([
                'agent_profile_id' => $offer->agent_profile_id,
                'agent_id' => $offer->agent_id,
                'tranche' => $tranche,
                'amount' => $amount,
                'currency' => 'UZS',
                'status' => PayoutStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
