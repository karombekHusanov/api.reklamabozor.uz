<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    | Comma-separated IPs or CIDR ranges whose X-Forwarded-* headers are
    | honoured when resolving the client IP / scheme. Only the nginx that
    | terminates TLS on this host should be here — trusting "*" lets any client
    | forge its IP with an X-Forwarded-For header and walk past every per-IP
    | throttle and the ATMOS callback allowlist (AUDIT_2026-10-16 §1.2).
    |
    | Read at request time (not in bootstrap/app.php) so it survives
    | `config:cache`. "*" is still accepted for setups behind a cloud LB.
    */
    'trusted' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];
