<?php

namespace App\Enums;

/**
 * Where an active deal stands on money, tracked separately from the order
 * status: since the contract acceptance activates the order immediately, work
 * and payment progress on their own timelines.
 */
enum OrderPaymentState: string
{
    /** Gateway disabled (offline MVP) — the platform does not collect for this order. */
    case NotRequired = 'not_required';
    /** Deal is active, the client still owes the payment. */
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isOutstanding(): bool
    {
        return $this === self::Unpaid;
    }
}
