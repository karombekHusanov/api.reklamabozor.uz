<?php

namespace App\Services\Payment\Gateway;

/** A card bound at the provider: its id, token and masked PAN. */
final readonly class BoundCard
{
    public function __construct(
        public string $cardId,
        public string $cardToken,
        public string $panMask,
    ) {}
}
