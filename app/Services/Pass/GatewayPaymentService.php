<?php

namespace App\Services\Pass;

use App\Contracts\PaymentGateway;
use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Enums\WalletTransactionType;
use App\Models\AgentPass;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\Payment\Gateway\GatewayEvent;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates gateway payments and applies their (idempotent) results. All state
 * changes happen under lockForUpdate on the payment row (GOTCHA #23), so a
 * duplicated callback can never activate two passes / credit twice.
 */
class GatewayPaymentService
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly WalletService $wallet,
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
        $activated = null;

        $payment = DB::transaction(function () use ($event, &$activated): ?GatewayPayment {
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

            $user = $payment->user;

            if ($payment->purpose === GatewayPaymentPurpose::Pass) {
                $hours = (int) ($payment->meta['hours'] ?? $this->settings->hours());
                $activated = app(PassService::class)->activate(
                    $user, $hours, $payment->amount_tiyin, 'gateway', $payment->id,
                );
            } else {
                $this->wallet->credit($user, WalletTransactionType::Topup, $payment->amount_tiyin, 'gp:'.$payment->id);
            }

            return $payment;
        });

        if ($activated instanceof AgentPass) {
            app(PassService::class)->notify($payment->user, $activated);
        }

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
