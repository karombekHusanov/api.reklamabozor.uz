<?php

/**
 * Kapitalbank (bank24.uz) OpenAPI.
 *
 * `GetDoc1C` (statement polling, read-only) auto-matches incoming bank
 * transfers to a pending `Payment` by contract number.
 *
 * `SendPaymentIBK` (agent payout queueing) only creates the payment order in
 * Kapitalbank's internet-bank system — per the bank's own guidance, actual
 * signing/sending stays a manual step on their website (ECP + OTP, with a
 * bulk-confirm option), so this never moves money by itself. Fully headless
 * signing (SendPayment) is a separate, still-blocked phase — see CLAUDE.md §12.
 *
 * Server-to-server integration: the account login/password is checked against
 * a whitelisted static IP, so every request can carry `Authorization: Basic`
 * directly — no `APILogin`/`sid` session dance needed for these calls.
 */
return [

    'base_url' => env('KAPITALBANK_BASE_URL', 'https://m.bank24.uz:2713/Mobile.svc'),

    'login' => env('KAPITALBANK_LOGIN'),
    'password' => env('KAPITALBANK_PASSWORD'),

    // Our Kapitalbank branch (mfo) + 20-digit settlement account, queried via
    // GetDoc1C and used as the sender side of SendPaymentIBK.
    'branch' => env('KAPITALBANK_BRANCH'),
    'account' => env('KAPITALBANK_ACCOUNT'),

    // Master switch — must be explicitly opted into before this integration
    // is live in any environment (including local/dev).
    'enabled' => env('KAPITALBANK_ENABLED', false),

    // How many days back GetDoc1C is queried per reconcile run (in case a
    // transfer posts a day late). 1 = today only.
    'poll_lookback_days' => (int) env('KAPITALBANK_POLL_LOOKBACK_DAYS', 1),

    // Our organization's identity for SendPaymentIBK's sender fields
    // (client_id/name_dt/inn_dt) — separate from branch/account above.
    'client_id' => env('KAPITALBANK_CLIENT_ID'),
    'sender_name' => env('KAPITALBANK_SENDER_NAME'),
    'sender_inn' => env('KAPITALBANK_SENDER_INN'),

    // purp_code has no published value list in the OpenAPI docs — the bank
    // must confirm the correct code for agent-payout transfers before this
    // is set; left unconfigured on purpose rather than guessed.
    'payout_purpose_code' => env('KAPITALBANK_PAYOUT_PURPOSE_CODE'),

    // "01"/"35" = "Платежное поручение", the only plain document types the
    // docs list as creatable via API (10.6 Типы документов).
    'payout_document_type' => env('KAPITALBANK_PAYOUT_DOCUMENT_TYPE', '01'),

    // anor=1 routes the payment through Kapitalbank's 24/7 Anor rail instead
    // of the 9:00–17:00 SEP window — matters since this is queued by cron.
    'payout_anor' => (bool) env('KAPITALBANK_PAYOUT_ANOR', true),

];
