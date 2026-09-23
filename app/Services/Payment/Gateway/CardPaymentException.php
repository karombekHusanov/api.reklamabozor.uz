<?php

namespace App\Services\Payment\Gateway;

use RuntimeException;

/**
 * A card step the customer can fix (declined card, wrong SMS code). The
 * message is safe to show — it never contains card data.
 */
class CardPaymentException extends RuntimeException {}
