<?php

namespace App\Services\Pass;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Agent-facing summary of the per-order fee and the wallet that pays it. (The
 * daily Propusk is retired — old `agent_passes` rows stay as history only.)
 */
class PassService
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly WalletService $wallet,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $agent): array
    {
        $walletOn = (bool) config('passes.wallet_enabled');

        return [
            'enforce' => (bool) config('passes.enforce'),
            'wallet_enabled' => $walletOn,
            // One price per order (Tender, and both Tezkor modes) for now.
            'response_price_som' => $this->settings->responsePriceSom(),
            // Billing new Tezkor requests get; each order carries its own in `billing`.
            'tezkor_billing' => $this->settings->tezkorBilling()->value,
            'pick_pay_minutes' => $this->settings->pickPayMinutes(),
            'balance_som' => $walletOn ? intdiv($this->wallet->balanceTiyin($agent), 100) : null,
        ];
    }

    /**
     * 402 with a stable `code` the mini app switches on.
     *
     * @param  array<string, mixed>  $data
     */
    public static function paymentRequired(string $code, string $message, array $data = []): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => $data ?: null,
        ], 402));
    }
}
