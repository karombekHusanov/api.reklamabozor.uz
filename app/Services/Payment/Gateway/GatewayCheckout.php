<?php

namespace App\Services\Payment\Gateway;

final readonly class GatewayCheckout
{
    public function __construct(
        public string $gatewayRef,
        public string $checkoutUrl,
    ) {}
}
