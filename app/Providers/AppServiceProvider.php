<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Online payment adapter, resolved lazily by driver name. `fake` is
        // dev-only; no driver configured (prod default) → RuntimeException,
        // which GatewayPaymentService turns into 503 gateway_unavailable.
        $this->app->bind(PaymentGateway::class, function ($app): PaymentGateway {
            $driver = config('passes.gateway');
            $class = $driver !== null ? config("passes.gateways.$driver") : null;

            if ($class === null) {
                throw new \RuntimeException('No payment gateway configured.');
            }

            if ($driver === 'fake' && $app->isProduction()) {
                throw new \RuntimeException('The fake payment gateway cannot be used in production.');
            }

            return $app->make($class);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
