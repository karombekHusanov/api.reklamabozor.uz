<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payout channel
    |--------------------------------------------------------------------------
    |
    | How the agent's share leaves the platform. Multicard exposes no API for
    | transfers to a settlement account (only card credit), so the bank channel
    | is executed by a manager against the agent's KYC requisites and recorded
    | with a payment-order reference.
    |
    */

    'channel' => env('PAYOUT_CHANNEL', 'bank'),

    /*
    |--------------------------------------------------------------------------
    | Agent self-service card cash-out
    |--------------------------------------------------------------------------
    |
    | The Multicard credit flow (hosted card form → credit → OTP). Off by
    | product decision: earnings are paid to the agent's bank account, not to a
    | card. Kept behind a flag so the built flow can be switched back on.
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
