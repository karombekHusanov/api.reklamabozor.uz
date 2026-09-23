<?php

/*
| ATMOS online payments (hosted checkout: Humo, UzCard, Visa, Mastercard).
| Used only by the Propusk gateway ({@see \App\Services\Payment\Gateway\AtmosPaymentGateway}).
| Enabled with PASSES_GATEWAY=atmos.
*/
return [
    'base_url' => rtrim((string) env('ATMOS_BASE_URL', 'https://apigw.atmos.uz'), '/'),
    'consumer_key' => env('ATMOS_CONSUMER_KEY'),
    'consumer_secret' => env('ATMOS_CONSUMER_SECRET'),
    'store_id' => env('ATMOS_STORE_ID'),

    // Provider-issued key used to sign the pre-debit billing callback.
    'api_key' => env('ATMOS_API_KEY'),
    // Hash algorithm of `sign` = hash(store_id.transaction_id.account.amount.api_key).
    'sign_algo' => env('ATMOS_SIGN_ALGO', 'md5'),

    // Only ATMOS servers may call the billing callback. Empty = no IP check.
    'callback_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATMOS_CALLBACK_IPS', '92.63.207.0/24'))))),

    // Invoice lifetime in minutes.
    'invoice_ttl' => (int) env('ATMOS_INVOICE_TTL', 30),

    // Where the browser returns after paying. Defaults to the mini app URL.
    'success_url' => env('ATMOS_SUCCESS_URL'),

    // Fiscal (OFD) line for the Propusk service.
    'item_name' => env('ATMOS_ITEM_NAME', 'Reklama Bozor — Propusk xizmati'),
    'ofd_code' => env('ATMOS_OFD_CODE'),
    'package_code' => env('ATMOS_PACKAGE_CODE'),

    'timeout' => (int) env('ATMOS_TIMEOUT', 15),
];
