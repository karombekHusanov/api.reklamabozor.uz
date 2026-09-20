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
    'terms_version' => env('LEGAL_TERMS_VERSION', 'v1'),

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
    | DRAFT values — replace with the real company requisites before launch.
    |
    */
    'platform' => [
        'name' => env('LEGAL_PLATFORM_NAME', '«Reklama Bozor» platformasi'),
        'legal_name' => env('LEGAL_PLATFORM_LEGAL_NAME'),
        'inn' => env('LEGAL_PLATFORM_INN'),
        'address' => env('LEGAL_PLATFORM_ADDRESS'),
        'phone' => env('LEGAL_PLATFORM_PHONE'),
        'work_hours' => env('LEGAL_PLATFORM_WORK_HOURS', '09:00–18:00'),
        'email' => env('LEGAL_PLATFORM_EMAIL', 'support@reklamabozor.uz'),
        'website' => env('LEGAL_PLATFORM_WEBSITE', 'reklamabozor.uz'),
        // Bank requisites printed on the invoice (hisob-faktura) a client pays
        // by transfer, and on the cash payment slip.
        'bank_name' => env('LEGAL_PLATFORM_BANK_NAME'),
        'bank_account' => env('LEGAL_PLATFORM_BANK_ACCOUNT'),
        'mfo' => env('LEGAL_PLATFORM_MFO'),
        'oked' => env('LEGAL_PLATFORM_OKED'),
        // Where a cash payment is accepted (office address / cash desk hours).
        'cash_desk' => env('LEGAL_PLATFORM_CASH_DESK'),
    ],
];
