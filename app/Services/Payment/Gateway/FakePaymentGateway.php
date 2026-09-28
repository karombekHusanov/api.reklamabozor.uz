<?php

namespace App\Services\Payment\Gateway;

use App\Contracts\CardPaymentGateway;
use App\Contracts\CardTokenGateway;
use App\Contracts\PaymentGateway;
use App\Contracts\RefundableGateway;
use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dev/testing driver. "Checkout" is a local endpoint that completes the
 * payment when opened; callbacks are unsigned. The in-app card form accepts
 * any well-formed card and the SMS code {@see FAKE_OTP}; a card ending in
 * 0000 is declined. Binding works the same way (code {@see FAKE_OTP}); a
 * token starting with "fake_declined" is declined when charged. Never
 * resolved in production.
 */
class FakePaymentGateway implements CardPaymentGateway, CardTokenGateway, PaymentGateway, RefundableGateway
{
    public const FAKE_OTP = '111111';

    public function name(): string
    {
        return 'fake';
    }

    public function createPayment(int $amountTiyin, string $reference, ?string $returnUrl = null): GatewayCheckout
    {
        $ref = 'fake_'.Str::lower(Str::random(20));

        return new GatewayCheckout($ref, url("/api/v1/payments/fake/{$ref}/complete"));
    }

    public function verifyCallback(Request $request): GatewayEvent
    {
        $ref = $request->input('gateway_ref');
        $status = GatewayPaymentStatus::tryFrom((string) $request->input('status'));

        if (! is_string($ref) || $ref === '' || $status === null) {
            throw new InvalidArgumentException('Malformed gateway callback.');
        }

        $amount = $request->input('amount_tiyin');

        return new GatewayEvent($ref, $status, $amount !== null ? (int) $amount : null);
    }

    public function startCardPayment(int $amountTiyin, string $reference): string
    {
        return 'fake_card_'.Str::lower(Str::random(20));
    }

    public function sendCardOtp(string $gatewayRef, string $cardNumber, string $expiryYymm): void
    {
        if (str_ends_with($cardNumber, '0000')) {
            throw new CardPaymentException('Karta rad etildi (test).');
        }
    }

    public function confirmCardOtp(string $gatewayRef, string $otp): GatewayEvent
    {
        if ($otp !== self::FAKE_OTP) {
            throw new CardPaymentException('SMS kod noto‘g‘ri (test).');
        }

        $payment = GatewayPayment::query()->where('gateway_ref', $gatewayRef)->firstOrFail();

        return new GatewayEvent($gatewayRef, GatewayPaymentStatus::Success, (int) $payment->amount_tiyin);
    }

    public function startCardBinding(string $cardNumber, string $expiryYymm): string
    {
        if (str_ends_with($cardNumber, '0000')) {
            throw new CardPaymentException('Karta rad etildi (test).');
        }

        // The binding ref carries the masked PAN so confirm can return it.
        return 'fake_bind_'.substr($cardNumber, 0, 4).substr($cardNumber, -4).'_'.Str::lower(Str::random(12));
    }

    public function confirmCardBinding(string $bindingRef, string $otp): BoundCard
    {
        if ($otp !== self::FAKE_OTP) {
            throw new CardPaymentException('SMS kod noto‘g‘ri (test).');
        }

        $digits = substr($bindingRef, strlen('fake_bind_'), 8);

        return new BoundCard(
            'fake_'.Str::lower(Str::random(10)),
            'fake_token_'.Str::random(24),
            substr($digits, 0, 4).' •••• '.substr($digits, 4, 4),
        );
    }

    public function chargeCardToken(string $gatewayRef, string $cardToken): GatewayEvent
    {
        if (str_starts_with($cardToken, 'fake_declined')) {
            throw new CardPaymentException('Kartada mablag‘ yetarli emas (test).');
        }

        $payment = GatewayPayment::query()->where('gateway_ref', $gatewayRef)->firstOrFail();

        return new GatewayEvent($gatewayRef, GatewayPaymentStatus::Success, (int) $payment->amount_tiyin);
    }

    public function removeCard(string $cardId, string $cardToken): void {}

    /** Card payments reverse fine; a ref containing "no_reverse" is refused (tests). */
    public function reverseCardPayment(string $gatewayRef, string $reason): void
    {
        if (str_contains($gatewayRef, 'no_reverse')) {
            throw new CardPaymentException('Qaytarish rad etildi (test).');
        }
    }

    public function getStatus(string $gatewayRef): GatewayEvent
    {
        $payment = GatewayPayment::query()->where('gateway_ref', $gatewayRef)->firstOrFail();

        return new GatewayEvent($gatewayRef, $payment->status, (int) $payment->amount_tiyin);
    }
}
