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
