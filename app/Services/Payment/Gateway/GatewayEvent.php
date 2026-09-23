<?php

namespace App\Services\Payment\Gateway;

use App\Enums\GatewayPaymentStatus;

final readonly class GatewayEvent
{
    public function __construct(
        public string $gatewayRef,
        public GatewayPaymentStatus $status,
        public ?int $amountTiyin = null,
    ) {}
}
