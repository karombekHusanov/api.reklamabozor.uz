<?php

namespace App\Services\Pass;

use App\Enums\GatewayPaymentPurpose;
use App\Enums\WalletTransactionType;
use App\Models\AgentPass;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Propusk: a paid time window that lets an agent claim Tezkor requests.
 * Every purchase is its own record; buying while a pass is active chains the
 * new window onto the end of the current one (i.e. extends expires_at).
 * No cancel, no refund (decision 12).
 */
class PassService
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly WalletService $wallet,
        private readonly GatewayPaymentService $gateway,
        private readonly OrderNotifier $notifier,
    ) {}

    /** The pass window that covers "now", or null. */
    public function activePass(User $agent): ?AgentPass
    {
        return AgentPass::query()
            ->where('user_id', $agent->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->first();
    }

    /** End of the whole contiguous pass chain, or null when there is no active pass. */
    public function expiresAt(User $agent): ?CarbonInterface
    {
        if ($this->activePass($agent) === null) {
            return null;
        }

        $max = AgentPass::query()
            ->where('user_id', $agent->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->max('expires_at');

        return $max !== null ? Carbon::parse($max) : null;
    }

    /**
     * Buy a Propusk. Wallet mode debits the balance and activates immediately;
     * otherwise a gateway payment is created and the pass is activated by its
     * successful callback.
     *
     * @return array{activated: bool, pass: ?AgentPass, checkout_url: ?string, payment_ref: ?string}
     */
    public function purchase(User $agent): array
    {
        $price = $this->settings->priceTiyin();
        $hours = $this->settings->hours();

        if (config('passes.wallet_enabled')) {
            $pass = DB::transaction(function () use ($agent, $price, $hours): AgentPass {
                User::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();

                if ($this->wallet->balanceTiyin($agent) < $price) {
                    throw self::paymentRequired('insufficient_balance', 'Insufficient wallet balance.');
                }

                $this->wallet->debit($agent, WalletTransactionType::Pass, $price, 'pass:'.Str::uuid());

                return $this->activate($agent, $hours, $price, 'wallet');
            });

            $this->notify($agent, $pass);

            return ['activated' => true, 'pass' => $pass, 'checkout_url' => null, 'payment_ref' => null];
        }

        $payment = $this->gateway->start($agent, GatewayPaymentPurpose::Pass, $price, ['hours' => $hours]);

        return ['activated' => false, 'pass' => null, 'checkout_url' => $payment->checkout_url, 'payment_ref' => $payment->reference];
    }

    /**
     * Create the pass row. Runs under a user-row lock so concurrent purchases
     * chain instead of overlapping.
     */
    public function activate(
        User $agent,
        int $hours,
        int $priceTiyin,
        string $source,
        ?int $gatewayPaymentId = null,
        ?User $by = null,
        ?string $note = null,
    ): AgentPass {
        return DB::transaction(function () use ($agent, $hours, $priceTiyin, $source, $gatewayPaymentId, $by, $note): AgentPass {
            User::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();

            $start = $this->expiresAt($agent) ?? now();

            return AgentPass::query()->create([
                'user_id' => $agent->id,
                'agent_profile_id' => $agent->profile()->value('id'),
                'starts_at' => $start,
                'expires_at' => $start->copy()->addHours($hours),
                'price_tiyin' => $priceTiyin,
                'source' => $source,
                'status' => 'active',
                'gateway_payment_id' => $gatewayPaymentId,
                'granted_by' => $by?->id,
                'note' => $note,
            ]);
        });
    }

    /** Manual admin grant: a free pass row, audited by granted_by + note. */
    public function grant(User $agent, int $hours, User $admin, string $reason): AgentPass
    {
        $pass = $this->activate($agent, $hours, 0, 'admin', null, $admin, $reason);

        Log::info('pass.granted', ['user_id' => $agent->id, 'by' => $admin->id, 'hours' => $hours, 'reason' => $reason]);

        $this->notify($agent, $pass);

        return $pass;
    }

    public function notify(User $agent, AgentPass $pass): void
    {
        try {
            $this->notifier->notifyPassActivated($agent, $pass);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, mixed> */
    public function summary(User $agent): array
    {
        $expires = $this->expiresAt($agent);
        $walletOn = (bool) config('passes.wallet_enabled');

        return [
            'active' => $expires !== null,
            'expires_at' => $expires?->toIso8601String(),
            'seconds_left' => $expires !== null ? max(0, (int) now()->diffInSeconds($expires, false)) : 0,
            'price_som' => $this->settings->priceSom(),
            'hours' => $this->settings->hours(),
            'mode' => $this->settings->mode()->value,
            'enforce' => (bool) config('passes.enforce'),
            'wallet_enabled' => $walletOn,
            'response_price_som' => (int) $this->settings->get('response_price_som'),
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
