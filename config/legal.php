<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Current public offer (Terms of Use) version
    |--------------------------------------------------------------------------
    |
    | Every user accepts the platform public offer at onboarding. Bump this
    | value whenever the offer text changes — users whose accepted version no
    | longer matches are re-prompted to accept before they can keep using the
    | app (see User::hasAcceptedCurrentTerms()).
    |
    */
    'terms_version' => env('LEGAL_TERMS_VERSION', 'v2'),

    /*
    |--------------------------------------------------------------------------
    | Platform requisites (third party of the per-order service contract)
    |--------------------------------------------------------------------------
    |
    | The platform signs the per-order contract as Operator only: marketplace,
    | payment operator, commission holder and dispute arbiter — never as the
    | provider of the advertising service itself. These requisites are printed
    | in the contract PDF and shown in the in-app accept drawer.
    |
    | Operator: «Imprint Business» MChJ — requisites as in the approved client
    | public offer (resources/legal/public_offer_client.php, §12).
    |
    */
    'platform' => [
        'name' => env('LEGAL_PLATFORM_NAME', '«Reklama Bozor» platformasi'),
        'legal_name' => env('LEGAL_PLATFORM_LEGAL_NAME', '«Imprint Business» MChJ'),
        'inn' => env('LEGAL_PLATFORM_INN', '311937654'),
        'address' => env('LEGAL_PLATFORM_ADDRESS', 'Toshkent shahri, Mirobod tumani, Afrosiyob MFY, Taras Shevchenko ko‘chasi, 22/1-uy, 3-xonadon'),
        'phone' => env('LEGAL_PLATFORM_PHONE'),
        'work_hours' => env('LEGAL_PLATFORM_WORK_HOURS', '09:00–18:00'),
        'email' => env('LEGAL_PLATFORM_EMAIL', 'support@reklamabozor.uz'),
        'website' => env('LEGAL_PLATFORM_WEBSITE', 'reklamabozor.uz'),
        // Bank requisites printed on the invoice (hisob-faktura) a client pays
        // by transfer, and on the cash payment slip.
        'bank_name' => env('LEGAL_PLATFORM_BANK_NAME', '«Kapitalbank» ATB'),
        'bank_account' => env('LEGAL_PLATFORM_BANK_ACCOUNT', '20208000707204832001'),
        'mfo' => env('LEGAL_PLATFORM_MFO', '01158'),
        'oked' => env('LEGAL_PLATFORM_OKED'),
        // Where a cash payment is accepted (office address / cash desk hours).
        'cash_desk' => env('LEGAL_PLATFORM_CASH_DESK'),
    ],
];
