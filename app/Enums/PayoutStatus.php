<?php

namespace App\Enums;

/**
 * Lifecycle of a payout (platform → agent). Manual releases jump straight to
 * paid; `processing`/`failed` are the seam for a future automated card-credit
 * flow (create → confirm → poll), currently unused.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';       // owed, not yet released by a manager
    case Processing = 'processing'; // credit in flight (future automated flow)
    case Paid = 'paid';             // transferred to the agent
    case Failed = 'failed';         // credit failed (future automated flow)
    case Cancelled = 'cancelled';   // voided (e.g. order refunded before release)

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }
}
