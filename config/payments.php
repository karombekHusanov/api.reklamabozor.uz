<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Order payments
    |--------------------------------------------------------------------------
    |
    | Business-level payment settings, independent of any particular gateway.
    | The client pays by cash or bank transfer (`App\Enums\PaymentMethod`); a
    | manager confirms it in the admin panel, or — for a bank transfer —
    | Kapitalbank auto-reconciliation matches it (config/kapitalbank.php).
    |
    */

    // Master switch. When false an accepted offer activates the deal with
    // `payment_state = not_required` — no money is collected at all.
    'enabled' => (bool) env('PAYMENTS_ENABLED', true),

    // How long the client has to pay once the deal is active (contract
    // accepted). Overdue orders are not cancelled — the client, the agent
    // and the ops group are reminded (orders:remind-unpaid).
    'payment_due_days' => (int) env('PAYMENT_DUE_DAYS', 3),

    // Platform commission on order payments, percent (deducted from payouts).
    'commission_percent' => (float) env('COMMISSION_PERCENT', 7),

    // Default advance slice of an agent payout, percent (final = remainder).
    // A manager can override the amount per payout at release time.
    'advance_percent' => (float) env('PAYOUT_ADVANCE_PERCENT', 40),

];
