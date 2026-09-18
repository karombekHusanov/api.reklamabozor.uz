<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Client cancellation window on a paid deal
    |--------------------------------------------------------------------------
    |
    | Accepting the contract activates the deal immediately, so the client gets
    | a short cooling-off window after paying: within this many minutes they may
    | still cancel (the payment is refunded), afterwards only support can. The
    | window is also the gate on the agent's advance payout — money only leaves
    | the platform once the client can no longer take it back. An unpaid active
    | deal can be cancelled at any time, because nothing has moved.
    |
    */
    'paid_cancel_window_minutes' => (int) env('ORDER_PAID_CANCEL_WINDOW_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Additional agreements (Qo'shimcha kelishuv)
    |--------------------------------------------------------------------------
    |
    | The client may only propose a change during the first slice of the agreed
    | delivery time — after that the work is too far along and only the agent
    | (who is doing it) may propose one. The window is
    | `activated_at + ceil(deadline_days / divisor)` days, floored at one day.
    | Deals with no committed deadline (legacy offers) fall back to a fixed span.
    |
    */
    'amendment_client_window_divisor' => (int) env('AMENDMENT_CLIENT_WINDOW_DIVISOR', 3),
    'amendment_client_window_fallback_days' => (int) env('AMENDMENT_CLIENT_WINDOW_FALLBACK_DAYS', 3),
    // How long the other party has to answer a proposal before it expires.
    'amendment_response_hours' => (int) env('AMENDMENT_RESPONSE_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Problem orders
    |--------------------------------------------------------------------------
    |
    | Two risk scenarios feed the "problem orders" admin queue:
    |  - a quality dispute (client rejected the delivered work) has this many
    |    days to reach `completed` before the daily sweep flags it;
    |  - a paid, active order whose agent never reported starting work lets the
    |    client report it once this many days have passed since activation.
    |
    */
    'quality_correction_window_days' => (int) env('ORDER_QUALITY_CORRECTION_DAYS', 3),
    'no_start_report_min_days' => (int) env('ORDER_NO_START_REPORT_MIN_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Stale (unanswered) orders
    |--------------------------------------------------------------------------
    |
    | An order still open for offers (new / offers_sent) that has received
    | zero offers after this many days gets a single one-time reminder to the
    | client (and a heads-up to ops) — no automatic re-broadcast or
    | cancellation, that stays a manual/product decision.
    |
    */
    'stale_order_reminder_days' => (int) env('ORDER_STALE_REMINDER_DAYS', 3),
];
