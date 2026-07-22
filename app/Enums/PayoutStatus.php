<?php

namespace App\Enums;

/**
 * Lifecycle of a payout (platform → agent). `manual` releases jump straight to
 * paid; the `processing` state is the seam for the future automated Multicard
 * "credit" flow (create → confirm → poll).
 */
enum PayoutStatus: string
{
    case Pending = 'pending';       // owed, not yet released by a manager
    case Processing = 'processing'; // gateway credit in flight (auto flow, v2)
    case Paid = 'paid';             // transferred to the agent
    case Failed = 'failed';         // gateway credit failed (auto flow, v2)
    case Cancelled = 'cancelled';   // voided (e.g. order refunded before release)

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }
}
