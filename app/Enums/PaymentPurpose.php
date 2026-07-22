<?php

namespace App\Enums;

/**
 * What a payment is for. Only order payments exist today; the enum is the seam
 * for future finance phases (provider payouts, platform commission, holds).
 */
enum PaymentPurpose: string
{
    case Order = 'order';
}
