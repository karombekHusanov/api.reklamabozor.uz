<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payout channel
    |--------------------------------------------------------------------------
    |
    | How the agent's share leaves the platform. There is no gateway API for
    | transfers to a settlement account, so the bank channel is executed by a
    | manager against the agent's KYC requisites and recorded with a
    | payment-order reference.
    |
    */

    'channel' => env('PAYOUT_CHANNEL', 'bank'),

    /*
    |--------------------------------------------------------------------------
    | Agent self-service card cash-out
    |--------------------------------------------------------------------------
    |
    | A gateway card-credit flow (hosted card form → credit → OTP). Off by
    | product decision: earnings are paid to the agent's bank account, not to a
    | card. The endpoints that drove this flow have been removed; this flag is
    | kept as a display switch (`GET /agent/payouts`) for a future re-build.
    |
    */

    'card_withdrawal_enabled' => env('PAYOUT_CARD_WITHDRAWAL_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Kapitalbank SendPaymentIBK queueing
    |--------------------------------------------------------------------------
    |
    | When enabled, releasable payouts are pushed to Kapitalbank's
    | internet-bank system as unsigned payment orders (input only — the bank
    | requires signing/sending to happen on their website, see
    | config/kapitalbank.php). Off by default: this creates real pending
    | payment orders at the bank, so it should only be switched on once the
    | Kapitalbank payout fields (client_id, sender identity, purpose code)
    | are confirmed and tested with a real small transfer.
    |
    */

    'bank_queue_enabled' => env('PAYOUT_BANK_QUEUE_ENABLED', false),

];
