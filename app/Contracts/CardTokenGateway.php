<?php

namespace App\Contracts;

use App\Services\Payment\Gateway\BoundCard;
use App\Services\Payment\Gateway\CardPaymentException;
use App\Services\Payment\Gateway\GatewayEvent;

/**
 * Card binding (tokenization): the cardholder confirms the card once with an
 * SMS code, the provider returns a token, and later payments are charged by
 * that token without the card number or another SMS code.
 */
interface CardTokenGateway
{
    /**
     * Start binding; the provider texts an SMS code to the cardholder.
     * Returns the binding ref confirmed in {@see confirmCardBinding()}.
     *
     * @throws CardPaymentException on a declined card.
     */
    public function startCardBinding(string $cardNumber, string $expiryYymm): string;

    /** @throws CardPaymentException on a wrong / expired code. */
    public function confirmCardBinding(string $bindingRef, string $otp): BoundCard;

    /**
     * Charge a transaction opened by {@see CardPaymentGateway::startCardPayment()}
     * with a bound card's token. Returns Success when the money was taken.
     *
     * @throws CardPaymentException when the provider declines the charge.
     */
    public function chargeCardToken(string $gatewayRef, string $cardToken): GatewayEvent;

    /** Revoke the token at the provider. */
    public function removeCard(string $cardId, string $cardToken): void;
}
