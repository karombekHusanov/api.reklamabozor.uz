<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nginx terminates TLS in front of php-fpm; without a trusted proxy,
        // $request->ip() is 127.0.0.1 and the ATMOS callback IP allowlist
        // rejects real webhooks. Only the local nginx is trusted (config
        // proxies.trusted / TRUSTED_PROXIES) — trusting every proxy would let
        // any client forge its IP via X-Forwarded-For.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);
        $middleware->throttleApi();
        // Pure API, no web login page: a guest must get a 401, never a
        // redirect to a `login` route that does not exist (which surfaced as a
        // 500 whenever a client omitted `Accept: application/json`).
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
