<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\User;

/**
 * Seam for the Propusk check before an agent claims a Tezkor request.
 * A no-op until `passes.enforce` is switched on in Faza 2.
 */
class PassGate
{
    public function assertCanClaim(User $agent, Order $order): void
    {
        if (! config('passes.enforce')) {
            return;
        }

        // Faza 2: verify an active Propusk for $agent and abort(402) otherwise.
    }
}
