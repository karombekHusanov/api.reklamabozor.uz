<?php

namespace App\Services\Pass;

use App\Contracts\CardPaymentGateway;
use App\Contracts\PaymentGateway;
use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\Payment\Gateway\CardPaymentException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Propusk bought from the in-app card form: start (card + expiry → the
 * provider texts an SMS code) then confirm (code → money taken → pass).
 *
 * The card number and expiry are handed to the provider and dropped — only a
 * masked number is kept in `meta` for display. Activation goes through
 * {@see GatewayPaymentService::handleEvent()} (row lock, idempotent).
 */
class CardPaymentService
{
    public function __construct(
        private readonly PassSettings $settings,
        private readonly GatewayPaymentService $payments,
    ) {}

    /** @param  string  $expiryYymm  Year + month, e.g. "2801" = 2028-01. */
    public function startPass(User $agent, string $cardNumber, string $expiryYymm): GatewayPayment
    {
        $gateway = $this->gateway();
        $amount = $this->settings->priceTiyin();

        $payment = GatewayPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'user_id' => $agent->id,
            'purpose' => GatewayPaymentPurpose::Pass,
            'amount_tiyin' => $amount,
            'status' => GatewayPaymentStatus::Pending,
            'gateway' => $gateway->name(),
            'meta' => [
                'hours' => $this->settings->hours(),
                'flow' => 'card',
                'card_mask' => self::mask($cardNumber),
            ],
        ]);

        try {
            $ref = $gateway->startCardPayment($amount, $payment->reference);
            $payment->update(['gateway_ref' => $ref]);
            $gateway->sendCardOtp($ref, $cardNumber, $expiryYymm);
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

        if ($payment === null || $payment->gateway_ref === null || ($payment->meta['flow'] ?? null) !== 'card') {
            throw $this->userError('Payment not found.', 'payment_not_found', 404);
        }

        if ($payment->status->isFinal()) {
            return $payment;
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
