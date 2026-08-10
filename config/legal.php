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
];
