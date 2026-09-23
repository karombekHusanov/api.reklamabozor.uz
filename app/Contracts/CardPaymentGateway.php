<?php

namespace App\Contracts;

use App\Services\Payment\Gateway\CardPaymentException;
use App\Services\Payment\Gateway\GatewayEvent;

/**
 * In-app card payment (card number + expiry → SMS code → confirm), for
 * drivers that support it next to their hosted checkout. The card data is
 * passed straight through to the provider and never stored or logged.
 */
interface CardPaymentGateway
{
    /** Open a provider transaction; returns its gateway ref. */
    public function startCardPayment(int $amountTiyin, string $reference): string;

    /**
     * Attach the card; the provider sends an SMS code to the cardholder.
     *
     * @param  string  $expiryYymm  Provider format: year + month, e.g. "2801" = 2028-01.
     *
     * @throws CardPaymentException with a user-safe message on a declined card.
     */
    public function sendCardOtp(string $gatewayRef, string $cardNumber, string $expiryYymm): void;

    /**
     * Confirm with the SMS code. Returns Success when the money was taken.
     *
     * @throws CardPaymentException on a wrong / expired code.
     */
    public function confirmCardOtp(string $gatewayRef, string $otp): GatewayEvent;
}
