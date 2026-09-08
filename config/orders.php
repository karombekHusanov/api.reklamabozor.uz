<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Client cancellation window on a paid deal
    |--------------------------------------------------------------------------
    |
    | Accepting the contract activates the deal immediately, so the client gets
    | a cooling-off window after paying: within this many hours they may still
    | cancel (the payment is refunded), afterwards only support can. An unpaid
    | active deal can be cancelled at any time — no money has moved.
    |
    */
    'paid_cancel_window_hours' => (int) env('ORDER_PAID_CANCEL_WINDOW_HOURS', 24),

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
];
