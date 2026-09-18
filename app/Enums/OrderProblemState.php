<?php

namespace App\Enums;

/**
 * Whether an order sits in the "problem orders" admin queue: a quality
 * dispute whose correction window ran out, or an agent who took the advance
 * and never started. Orthogonal to `OrderStatus` — the deal keeps its own
 * status while this tracks the separate risk-review track.
 */
enum OrderProblemState: string
{
    case None = 'none';
    case Flagged = 'flagged';
    case Resolved = 'resolved';
}
