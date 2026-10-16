<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trust only the proxies listed in config/proxies.php (TRUSTED_PROXIES).
 *
 * The framework's `trustProxies(at: …)` is evaluated while bootstrapping,
 * before the configuration is loaded, so an env()-driven list there would
 * silently become empty under `config:cache`. Resolving the list here, per
 * request, keeps it configurable and cache-safe.
 */
class TrustProxies extends Middleware
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $configured = config('proxies.trusted');

        if (is_array($configured)) {
            return $configured === [] ? null : $configured;
        }

        $list = array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            explode(',', (string) $configured),
        )));

        if ($list === ['*']) {
            return '*';
        }

        return $list === [] ? null : $list;
    }
}
