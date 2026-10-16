<?php

namespace App\Services\Pass;

use App\Contracts\PaymentGateway;
use App\Contracts\RefundableGateway;
use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Enums\WalletTransactionType;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\Payment\Gateway\CardPaymentException;
use App\Services\Payment\Gateway\GatewayEvent;
use App\Services\Telegram\PaymentFeedNotifier;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates gateway payments and applies their (idempotent) results. All state
 * changes happen under lockForUpdate on the payment row (GOTCHA #23), so a
 * duplicated callback can never credit twice.
 */
class GatewayPaymentService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PaymentFeedNotifier $feed,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function start(User $user, GatewayPaymentPurpose $purpose, int $amountTiyin, array $meta = []): GatewayPayment
    {
        $gateway = $this->gateway();

        $payment = GatewayPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'purpose' => $purpose,
            'amount_tiyin' => $amountTiyin,
            'status' => GatewayPaymentStatus::Pending,
            'gateway' => $gateway->name(),
            'meta' => $meta,
        ]);

        $checkout = $gateway->createPayment($amountTiyin, $payment->reference, config('services.telegram.mini_app_url'));

        $payment->update(['gateway_ref' => $checkout->gatewayRef, 'checkout_url' => $checkout->checkoutUrl]);

        return $payment;
    }

    /**
     * Apply a provider event. Returns the payment (null when unknown). Safe to
     * call any number of times with the same event.
     */
    public function handleEvent(GatewayEvent $event): ?GatewayPayment
    {
        $payment = DB::transaction(function () use ($event): ?GatewayPayment {
            /** @var GatewayPayment|null $payment */
            $payment = GatewayPayment::query()->where('gateway_ref', $event->gatewayRef)->lockForUpdate()->first();

            if ($payment === null) {
                Log::warning('gateway.callback.unknown_ref', ['ref' => $event->gatewayRef]);

                return null;
            }

            // Final statuses are sticky — a replay changes nothing.
            if ($payment->status->isFinal()) {
                return $payment;
            }

            if ($event->status === GatewayPaymentStatus::Failed) {
                $payment->update(['status' => GatewayPaymentStatus::Failed]);

                return $payment;
            }

            if ($event->status !== GatewayPaymentStatus::Success) {
                return $payment;
            }

            if ($event->amountTiyin !== null && $event->amountTiyin !== $payment->amount_tiyin) {
                Log::warning('gateway.callback.amount_mismatch', ['payment' => $payment->id, 'got' => $event->amountTiyin]);

                return $payment;
            }

            $payment->update(['status' => GatewayPaymentStatus::Success, 'paid_at' => now()]);
            $this->feed->gatewayPaid($payment);

            $user = $payment->user;

            // Every paid payment lands on the balance. The daily Propusk is
            // retired — a pass payment started before that still reaches the
            // agent as balance for per-order fees.
            $this->wallet->credit($user, WalletTransactionType::Topup, $payment->amount_tiyin, 'gp:'.$payment->id);

            return $payment;
        });

        return $payment;
    }

    /**
     * Pull the provider's current state for a pending payment and apply it.
     * Used where the provider does not push a paid notification (ATMOS).
     */
    public function sync(GatewayPayment $payment): GatewayPayment
    {
        if ($payment->status->isFinal() || $payment->gateway_ref === null) {
            return $payment;
        }

        try {
            $event = $this->gateway()->getStatus($payment->gateway_ref);
        } catch (\Throwable $e) {
            Log::warning('gateway.sync.failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return $payment;
        }

        return $this->handleEvent($event) ?? $payment;
    }

    /**
     * Admin full refund of a successful card payment: take back the wallet
     * credit it bought, then reverse the debit at the provider — all in one
     * transaction, so a refused reversal changes nothing. A top-up the agent
     * has already spent cannot be refunded (422). (Card payments only ever
     * credit the balance now; the daily Propusk they used to buy is retired.)
     */
    public function refund(GatewayPayment $payment, User $admin, string $reason): GatewayPayment
    {
        return DB::transaction(function () use ($payment, $admin, $reason): GatewayPayment {
            /** @var GatewayPayment $payment */
            $payment = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== GatewayPaymentStatus::Success) {
                throw ValidationException::withMessages(['payment' => ['Only a successful payment can be refunded.']]);
            }

            $gateway = $this->gateway();
            if (! $gateway instanceof RefundableGateway || $gateway->name() !== $payment->gateway || $payment->gateway_ref === null) {
                throw ValidationException::withMessages(['payment' => ['This payment cannot be refunded through the gateway.']]);
            }

            $this->wallet->debit(
                $payment->user, WalletTransactionType::Refund, $payment->amount_tiyin, 'gp-refund:'.$payment->id, $reason, $admin,
            );

            try {
                $gateway->reverseCardPayment($payment->gateway_ref, $reason);
            } catch (CardPaymentException $e) {
                throw ValidationException::withMessages(['payment' => [$e->getMessage()]]);
            }

            $payment->update([
                'status' => GatewayPaymentStatus::Refunded,
                'meta' => array_merge($payment->meta ?? [], [
                    'refund' => ['at' => now()->toIso8601String(), 'by' => $admin->id, 'reason' => $reason],
                ]),
            ]);

            Log::info('gateway.payment.refunded', ['payment' => $payment->id, 'by' => $admin->id]);
            $this->feed->gatewayRefunded($payment);

            return $payment;
        });
    }

    /** Give up on a payment whose provider invoice can no longer be paid. */
    public function expire(GatewayPayment $payment): void
    {
        GatewayPayment::query()
            ->whereKey($payment->id)
            ->where('status', GatewayPaymentStatus::Pending)
            ->update(['status' => GatewayPaymentStatus::Failed]);
    }

    private function gateway(): PaymentGateway
    {
        try {
            return app(PaymentGateway::class);
        } catch (\RuntimeException $e) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Online payment is not available right now.',
                'code' => 'gateway_unavailable',
                'data' => null,
            ], 503));
        }
    }
}
