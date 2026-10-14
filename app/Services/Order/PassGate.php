<?php

namespace App\Services\Order;

use App\Enums\WalletTransactionType;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Pass\PassService;
use App\Services\Pass\PassSettings;
use App\Services\Pass\WalletService;

/**
 * Per-order fee, taken from the agent's wallet balance — once per agent per
 * order, never at view time. When it is taken depends on the order:
 *  - Tender and Tezkor `pay_to_respond`: on the otklik ({@see chargeOtklik});
 *  - Tezkor `pay_on_pick`: the otklik is free, the agent the client picked
 *    pays to take the job ({@see chargeTake}).
 * Callers run inside their own transaction, so a fee rolls back with a failed
 * otklik / take.
 *
 * Failures are HTTP 402 with a stable `code`:
 *  - insufficient_balance        wallet too low
 *  - payment_source_unavailable  fees on but the wallet is off
 */
class PassGate
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly WalletService $wallet,
    ) {}

    /** The fee this agent pays for this order when it is charged (0 = free). */
    public function feeTiyin(Order $order): int
    {
        return config('passes.enforce') ? $this->settings->feeTiyinFor($order) : 0;
    }

    /** Fee due on the otklik itself; 0 when free (fees off, or pay_on_pick). */
    public function otklikFeeTiyin(Order $order): int
    {
        return $order->chargesOnRespond() ? $this->feeTiyin($order) : 0;
    }

    /**
     * What the mini app shows for this order: when the fee is taken and how
     * much. Null `mode` = Tender (always on the otklik).
     *
     * @return array{mode: ?string, otklik_fee_som: int, take_fee_som: int}
     */
    public function billingFor(Order $order): array
    {
        return [
            'mode' => $order->tezkorBilling()?->value,
            'otklik_fee_som' => intdiv($this->otklikFeeTiyin($order), 100),
            'take_fee_som' => $order->chargesOnRespond() ? 0 : intdiv($this->feeTiyin($order), 100),
        ];
    }

    /** Charge the otklik fee (if any) and return what was taken. */
    public function chargeOtklik(User $agent, Order $order): int
    {
        return $this->charge($agent, $order, $this->otklikFeeTiyin($order), WalletTransactionType::ResponseFee, 'otklik');
    }

    /** pay_on_pick: the picked agent pays to take the job; records the fee on the offer. */
    public function chargeTake(User $agent, Offer $offer): int
    {
        $order = $offer->order;
        $fee = $this->charge($agent, $order, $this->feeTiyin($order), WalletTransactionType::TakeFee, 'take');

        if ($fee > 0) {
            $offer->update(['fee_tiyin' => $fee, 'fee_paid_at' => now()]);
        }

        return $fee;
    }

    private function charge(User $agent, Order $order, int $fee, WalletTransactionType $type, string $stage): int
    {
        if ($fee <= 0) {
            return 0;
        }

        if (! config('passes.wallet_enabled')) {
            throw PassService::paymentRequired('payment_source_unavailable', 'Paying for requests is not available right now.');
        }

        $balance = $this->wallet->balanceTiyin($agent);

        if ($balance < $fee) {
            throw PassService::paymentRequired('insufficient_balance', 'Insufficient wallet balance for this request.', [
                'price_som' => intdiv($fee, 100),
                'balance_som' => intdiv($balance, 100),
            ]);
        }

        // One fee per agent per order: the reference is deterministic, so a
        // retried request returns the existing debit instead of charging twice.
        $this->wallet->debit($agent, $type, $fee, "{$stage}:{$order->id}:{$agent->id}");

        return $fee;
    }
}
