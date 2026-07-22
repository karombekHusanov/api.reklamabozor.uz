<?php

namespace App\Enums;

/**
 * Which slice of an order's escrow a payout represents. The default split is
 * advance (on deal start) + final (on completion); `adjustment` covers manual
 * corrections a manager may add (e.g. a bonus or a dispute settlement top-up).
 */
enum PayoutTranche: string
{
    case Advance = 'advance';       // released when the deal starts (in_progress)
    case Final = 'final';           // released when the order completes
    case Adjustment = 'adjustment'; // manual correction by a manager
}
