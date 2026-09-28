<?php

namespace App\Enums;

enum GatewayPaymentStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    /** Paid, then fully reversed back to the card (admin refund). */
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
