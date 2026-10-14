<?php

use App\Services\Payment\Gateway\AtmosPaymentGateway;
use App\Services\Payment\Gateway\FakePaymentGateway;

return [
    /*
    | Per-order fee an agent pays for a request, through the wallet balance.
    | {@see \App\Services\Order\PassGate}. Values below are defaults; an admin can
    | override price / Tezkor billing / pick window from the panel
    | ({@see \App\Services\Pass\PassSettings}).
    */
    'enforce' => (bool) env('PASSES_ENFORCE', false),

    // One price per order for now (later it may depend on the order size).
    // Configured in so'm, charged in tiyin (x100).
    'response_price_som' => (int) env('PASSES_RESPONSE_PRICE_SOM', 1000),

    // Tezkor: pay_to_respond (fee on otklik) | pay_on_pick (free otklik, the
    // picked agent pays to take the job). Tender always charges on otklik.
    'tezkor_billing' => env('PASSES_TEZKOR_BILLING', 'pay_to_respond'),

    // pay_on_pick: minutes the picked agent has to pay before the pick lapses.
    'pick_pay_minutes' => (int) env('PASSES_PICK_PAY_MINUTES', 30),

    // Wallet ledger. Per-order fees are debited from the balance.
    'wallet_enabled' => (bool) env('PASSES_WALLET_ENABLED', false),

    // Payment gateway driver (wallet top-ups). `fake` is a dev-only driver (refused in production).
    'gateway' => env('PASSES_GATEWAY', env('APP_ENV') === 'production' ? null : 'fake'),

    'gateways' => [
        'fake' => FakePaymentGateway::class,
        'atmos' => AtmosPaymentGateway::class,
    ],
];
