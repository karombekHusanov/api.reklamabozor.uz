<?php

namespace App\Services\Order;

use App\Enums\PassMode;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\User;
use App\Services\Pass\PassService;
use App\Services\Pass\PassSettings;
use App\Services\Pass\WalletService;
use Illuminate\Support\Str;

/**
 * Propusk check when an agent responds (otklik) — Tezkor and Tender alike,
 * never at view time. Called inside the offer transaction, so a response fee
 * charged here rolls back together with a failed otklik. Applies to directed
 * requests too. There is no cap on how many requests an agent answers — any
 * agent who pays may respond and the client picks.
 *
 * Failures are HTTP 402 with a stable `code`:
 *  - pass_required        daily_pass mode, no active Propusk
 *  - insufficient_balance per_response mode, wallet too low
 *  - payment_source_unavailable  per_response mode but the wallet is off
 */
class PassGate
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly PassService $passes,
        private readonly WalletService $wallet,
    ) {}

    public function assertCanRespond(User $agent, Order $order): void
    {
        if (! config('passes.enforce')) {
            return;
        }

        if ($this->settings->mode() === PassMode::DailyPass) {
            if ($this->passes->activePass($agent) === null) {
                throw PassService::paymentRequired('pass_required', 'An active Propusk is required to respond.', [
                    'price_som' => $this->settings->priceSom(),
                    'hours' => $this->settings->hours(),
                ]);
            }

            return;
        }

        // per_response: one fee per otklik (both routes), through the wallet ledger.
        if (! config('passes.wallet_enabled')) {
            throw PassService::paymentRequired('payment_source_unavailable', 'Pay-per-response is not available right now.');
        }

        $fee = $this->settings->responsePriceTiyin();

        if ($this->wallet->balanceTiyin($agent) < $fee) {
            throw PassService::paymentRequired('insufficient_balance', 'Insufficient wallet balance for this response.', [
                'price_som' => intdiv($fee, 100),
                'balance_som' => intdiv($this->wallet->balanceTiyin($agent), 100),
            ]);
        }

        $this->wallet->debit($agent, WalletTransactionType::ResponseFee, $fee, "otklik:{$order->id}:{$agent->id}:".Str::uuid());
    }
}
