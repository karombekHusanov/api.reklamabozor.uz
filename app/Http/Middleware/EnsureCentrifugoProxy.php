<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centrifugo → backend proxy calls carry a static shared-secret header
 * (configured in Centrifugo, never exposed to clients). Anything else is 403.
 */
class EnsureCentrifugoProxy
{
    public const HEADER = 'X-Centrifugo-Proxy-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('realtime.proxy_secret');
        $given = (string) $request->header(self::HEADER, '');

        abort_if($secret === '' || ! hash_equals($secret, $given), 403);

        return $next($request);
    }
}
