<?php

use App\Services\Payment\Gateway\AtmosPaymentGateway;
use App\Services\Payment\Gateway\FakePaymentGateway;

return [
    /*
    | Propusk (agent access pass) / per-otklik fee for responding to requests.
    | {@see \App\Services\Order\PassGate}. Values below are defaults; an admin can
    | override mode / price / hours / response price from the panel
    | ({@see \App\Services\Pass\PassSettings}).
    */
    'enforce' => (bool) env('PASSES_ENFORCE', false),

    // daily_pass | per_response
    'mode' => env('PASSES_MODE', 'daily_pass'),

    // Prices are configured in so'm and stored/charged in tiyin (x100).
    'price_som' => (int) env('PASSES_PRICE_SOM', 1000),
    'hours' => (int) env('PASSES_HOURS', 24),
    'response_price_som' => (int) env('PASSES_RESPONSE_PRICE_SOM', 1000),

    // Dormant ledger. When on, passes / response fees are debited from the balance.
    'wallet_enabled' => (bool) env('PASSES_WALLET_ENABLED', false),

    // Payment gateway driver. `fake` is a dev-only driver (refused in production).
    'gateway' => env('PASSES_GATEWAY', env('APP_ENV') === 'production' ? null : 'fake'),

    'gateways' => [
        'fake' => FakePaymentGateway::class,
        'atmos' => AtmosPaymentGateway::class,
    ],
];
