<?php

namespace App\Services\Payment\Gateway;

use RuntimeException;

/**
 * A card step the customer can fix (declined card, wrong SMS code). The
 * message is safe to show — it never contains card data. `providerCode` is
 * the gateway's own error code (ATMOS `result.code`), kept for the logs and
 * the API response so failures can be grouped and asked about.
 */
class CardPaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $providerCode = null)
    {
        parent::__construct($message);
    }
}
