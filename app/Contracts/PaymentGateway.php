<?php

namespace App\Contracts;

use App\Services\Payment\Gateway\GatewayCheckout;
use App\Services\Payment\Gateway\GatewayEvent;
use Illuminate\Http\Request;

/**
 * Adapter for the online payment provider (Payme / Click / Atmos / ... — not
 * chosen yet). The platform only knows this contract.
 */
interface PaymentGateway
{
    /** Driver name, stored on gateway_payments.gateway. */
    public function name(): string;

    public function createPayment(int $amountTiyin, string $reference, ?string $returnUrl = null): GatewayCheckout;

    /**
     * Authenticate and parse a provider callback. Must throw when the
     * signature/credentials are invalid.
     */
    public function verifyCallback(Request $request): GatewayEvent;

    /** Ask the provider for the current state of a payment. */
    public function getStatus(string $gatewayRef): GatewayEvent;
}
