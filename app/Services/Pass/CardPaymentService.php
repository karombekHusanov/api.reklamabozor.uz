<?php

namespace App\Services\Pass;

use App\Contracts\CardPaymentGateway;
use App\Contracts\CardTokenGateway;
use App\Contracts\PaymentGateway;
use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayPayment;
use App\Models\SavedCard;
use App\Models\User;
use App\Services\Payment\Gateway\CardPaymentException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * In-app card form: start (card + expiry → the provider texts an SMS code)
 * then confirm (code → money taken). Buys a Propusk (daily_pass mode) or tops
 * up the wallet the per-otklik fee is taken from (per_response mode).
 *
 * Three ways to pay ({@see CardSource}):
 *  - new card — pre-apply sends the SMS code, confirm takes the money;
 *  - new card + "save" — the SMS code binds the card at the provider
 *    (bind-card), the token is saved and charged right away: still one SMS;
 *  - saved card — charged by its token at once, no card data, no SMS.
 *
 * The card number and expiry are handed to the provider and dropped — only a
 * masked number is kept (`meta.card_mask`, `saved_cards.pan_mask`) and the
 * provider token is encrypted at rest. Activation goes through
 * {@see GatewayPaymentService::handleEvent()} (row lock, idempotent).
 */
class CardPaymentService
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly GatewayPaymentService $payments,
        private readonly SavedCardService $cards,
    ) {}

    public function startPass(User $agent, CardSource $source): GatewayPayment
    {
        return $this->start(
            $agent, GatewayPaymentPurpose::Pass, $this->settings->priceTiyin(),
            ['hours' => $this->settings->hours()], $source,
        );
    }

    /** Wallet top-up; confirmed payments are credited by {@see GatewayPaymentService::handleEvent()}. */
    public function startTopup(User $agent, int $amountTiyin, CardSource $source): GatewayPayment
    {
        return $this->start($agent, GatewayPaymentPurpose::Topup, $amountTiyin, [], $source);
    }

    /**
     * A saved-card payment comes back settled (or failed); a new-card one is
     * pending until {@see confirm()} with the SMS code.
     *
     * @param  array<string, mixed>  $meta
     */
    private function start(User $agent, GatewayPaymentPurpose $purpose, int $amount, array $meta, CardSource $source): GatewayPayment
    {
        $gateway = $this->gateway();

        if (($source->savedCard !== null || $source->save) && ! $gateway instanceof CardTokenGateway) {
            throw $this->unavailable();
        }

        $payment = GatewayPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'user_id' => $agent->id,
            'purpose' => $purpose,
            'amount_tiyin' => $amount,
            'status' => GatewayPaymentStatus::Pending,
            'gateway' => $gateway->name(),
            'meta' => $meta + array_filter([
                'flow' => 'card',
                'card_mask' => $source->savedCard?->pan_mask ?? self::mask((string) $source->cardNumber),
                'saved_card_id' => $source->savedCard?->id,
            ]),
        ]);

        if ($source->savedCard !== null) {
            return $this->chargeSaved($payment, $source->savedCard);
        }

        try {
            if ($source->save) {
                /** @var CardTokenGateway $gateway */
                $bindRef = $gateway->startCardBinding((string) $source->cardNumber, (string) $source->expiryYymm);
                $payment->update(['meta' => $payment->meta + ['bind_ref' => $bindRef]]);
            } else {
                $ref = $gateway->startCardPayment($amount, $payment->reference);
                $payment->update(['gateway_ref' => $ref]);
                $gateway->sendCardOtp($ref, (string) $source->cardNumber, (string) $source->expiryYymm);
            }
        } catch (CardPaymentException $e) {
            $this->fail($payment);

            throw $this->userError($e->getMessage(), 'card_declined');
        } catch (\Throwable $e) {
            $this->fail($payment);
            Log::warning('gateway.card.start_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            throw $this->unavailable();
        }

        return $payment;
    }

    /** Returns the settled payment; a wrong code leaves it pending for a retry. */
    public function confirm(User $agent, string $reference, string $otp): GatewayPayment
    {
        $payment = GatewayPayment::query()
            ->where('reference', $reference)
            ->where('user_id', $agent->id)
            ->first();

        $binding = isset($payment?->meta['bind_ref']);

        if ($payment === null || ($payment->meta['flow'] ?? null) !== 'card' || (! $binding && $payment->gateway_ref === null)) {
            throw $this->userError('Payment not found.', 'payment_not_found', 404);
        }

        if ($payment->status->isFinal()) {
            return $payment;
        }

        if ($binding) {
            return $this->confirmBinding($agent, $payment, $otp);
        }

        try {
            $event = $this->gateway()->confirmCardOtp($payment->gateway_ref, $otp);
        } catch (CardPaymentException $e) {
            throw $this->userError($e->getMessage(), 'otp_invalid');
        } catch (\Throwable $e) {
            // The debit may still have gone through — the status sync settles it.
            Log::warning('gateway.card.confirm_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return $this->payments->sync($payment);
        }

        return $this->payments->handleEvent($event) ?? $payment->refresh();
    }

    /**
     * "Save card" flow: the SMS code binds the card, the token is saved and the
     * payment charged with it. Locked so a double tap cannot charge twice.
     */
    private function confirmBinding(User $agent, GatewayPayment $payment, string $otp): GatewayPayment
    {
        $lock = Cache::lock("gateway.card.bind.{$payment->id}", 30);

        if (! $lock->get()) {
            throw $this->userError('The payment is already being processed.', 'payment_in_progress', 409);
        }

        try {
            $payment->refresh();

            if ($payment->status->isFinal()) {
                return $payment;
            }

            // Bound on an earlier attempt, but the charge did not settle.
            if ($payment->gateway_ref !== null) {
                return $this->payments->sync($payment);
            }

            /** @var CardPaymentGateway&CardTokenGateway&PaymentGateway $gateway */
            $gateway = $this->gateway();

            try {
                $bound = $gateway->confirmCardBinding((string) $payment->meta['bind_ref'], $otp);
            } catch (CardPaymentException $e) {
                throw $this->userError($e->getMessage(), 'otp_invalid');
            } catch (\Throwable $e) {
                Log::warning('gateway.card.bind_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

                throw $this->unavailable();
            }

            $card = $this->cards->save($agent, $gateway->name(), $bound);

            $payment->update(['meta' => array_merge($payment->meta, [
                'saved_card_id' => $card->id,
                'card_mask' => $card->pan_mask,
            ])]);

            return $this->chargeSaved($payment, $card);
        } finally {
            $lock->release();
        }
    }

    /** Open the provider transaction and take the money by the card token. */
    private function chargeSaved(GatewayPayment $payment, SavedCard $card): GatewayPayment
    {
        /** @var CardPaymentGateway&CardTokenGateway&PaymentGateway $gateway */
        $gateway = $this->gateway();

        try {
            $ref = $gateway->startCardPayment((int) $payment->amount_tiyin, $payment->reference);
            $payment->update(['gateway_ref' => $ref]);
        } catch (CardPaymentException $e) {
            $this->fail($payment);

            throw $this->userError($e->getMessage(), 'card_declined');
        } catch (\Throwable $e) {
            $this->fail($payment);
            Log::warning('gateway.card.start_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            throw $this->unavailable();
        }

        try {
            $event = $gateway->chargeCardToken($ref, $card->card_token);
        } catch (CardPaymentException $e) {
            $this->fail($payment);

            throw $this->userError($e->getMessage(), 'card_declined');
        } catch (\Throwable $e) {
            // The debit may still have gone through — the status sync settles it.
            Log::warning('gateway.card.token_charge_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return $this->payments->sync($payment);
        }

        $card->update(['last_used_at' => now()]);

        return $this->payments->handleEvent($event) ?? $payment->refresh();
    }

    /** "8600 •••• 2365" — never more than the first 4 and last 4 digits. */
    public static function mask(string $cardNumber): string
    {
        return substr($cardNumber, 0, 4).' •••• '.substr($cardNumber, -4);
    }

    private function gateway(): CardPaymentGateway&PaymentGateway
    {
        try {
            $gateway = app(PaymentGateway::class);
        } catch (\RuntimeException) {
            throw $this->unavailable();
        }

        if (! $gateway instanceof CardPaymentGateway) {
            throw $this->unavailable();
        }

        return $gateway;
    }

    private function fail(GatewayPayment $payment): void
    {
        $payment->update(['status' => GatewayPaymentStatus::Failed]);
    }

    private function userError(string $message, string $code, int $status = 422): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => null,
        ], $status));
    }

    private function unavailable(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Online payment is not available right now.',
            'code' => 'gateway_unavailable',
            'data' => null,
        ], 503));
    }
}
