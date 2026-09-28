<?php

namespace App\Contracts;

use App\Services\Payment\Gateway\CardPaymentException;

/**
 * Full reversal of a completed card payment: the provider returns the money
 * to the card. Used by the admin refund of a Propusk / wallet top-up payment.
 */
interface RefundableGateway
{
    /**
     * @throws CardPaymentException when the provider refuses (already reversed, not found, …).
     */
    public function reverseCardPayment(string $gatewayRef, string $reason): void;
}
