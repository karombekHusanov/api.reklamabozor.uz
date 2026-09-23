<?php

use App\Services\Payment\Gateway\AtmosPaymentGateway;
use App\Services\Payment\Gateway\FakePaymentGateway;

return [
    /*
    | Propusk (agent access pass) for claiming Tezkor requests.
    | {@see \App\Services\Order\PassGate}. Values below are defaults; an admin can
    | override mode / price / hours / max_active_claims from the panel
    | ({@see \App\Services\Pass\PassSettings}).
    */
    'enforce' => (bool) env('PASSES_ENFORCE', false),

    // daily_pass | per_response
    'mode' => env('PASSES_MODE', 'daily_pass'),

    // Prices are configured in so'm and stored/charged in tiyin (x100).
    'price_som' => (int) env('PASSES_PRICE_SOM', 1000),
    'hours' => (int) env('PASSES_HOURS', 24),
    'response_price_som' => (int) env('PASSES_RESPONSE_PRICE_SOM', 1000),

    // Open claimed Tezkor requests one agent may hold at once. Default 3 so one
    // agent (or a bot) cannot hoard every fresh request the moment it lands;
    // an admin can raise it, or set PASSES_MAX_ACTIVE_CLAIMS= (empty) for
    // unlimited. Independent of `enforce` — always applied (see PassGate).
    'max_active_claims' => env('PASSES_MAX_ACTIVE_CLAIMS', '3') === ''
        ? null
        : (int) env('PASSES_MAX_ACTIVE_CLAIMS', '3'),

    // Dormant ledger. When on, passes / response fees are debited from the balance.
    'wallet_enabled' => (bool) env('PASSES_WALLET_ENABLED', false),

    // Payment gateway driver. `fake` is a dev-only driver (refused in production).
    'gateway' => env('PASSES_GATEWAY', env('APP_ENV') === 'production' ? null : 'fake'),

    'gateways' => [
        'fake' => FakePaymentGateway::class,
        'atmos' => AtmosPaymentGateway::class,
    ],
];
