<?php

namespace App\Services\Payment;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper over the Multicard payment gateway (docs.multicard.uz).
 *
 * Auth is a short-lived bearer token obtained from POST /auth; we cache it
 * until just before its stated expiry so we don't re-auth on every call.
 */
class MulticardClient
{
    private const TOKEN_CACHE_KEY = 'multicard:token';

    private function baseUrl(): string
    {
        return rtrim((string) config('services.multicard.base_url'), '/');
    }

    /**
     * Obtain (and cache) a bearer token. The gateway returns an `expiry`
     * timestamp (GMT+5); we cache slightly short of it.
     */
    public function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asJson()->post($this->baseUrl().'/auth', [
            'application_id' => (string) config('services.multicard.application_id'),
            'secret' => (string) config('services.multicard.secret'),
        ]);

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Multicard auth failed: '.$response->body());
        }

        $ttl = $this->tokenTtl($response->json('expiry'));
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Create a hosted-checkout invoice. Returns the gateway `data` payload
     * (uuid, checkout_url, short_link, deeplink, ...).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        $response = $this->authed()->post($this->baseUrl().'/payment/invoice', $payload);

        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('Multicard invoice creation failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Fetch the current state of a payment by its gateway uuid.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $uuid): array
    {
        $response = $this->authed()->get($this->baseUrl().'/payment/'.$uuid);

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Verify a callback signature: sha1(uuid + invoice_id + amount + secret).
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallbackSign(array $payload): bool
    {
        $provided = (string) ($payload['sign'] ?? '');

        if ($provided === '') {
            return false;
        }

        return hash_equals($this->expectedCallbackSign($payload), $provided);
    }

    /**
     * Expected callback signature: sha1(uuid + invoice_id + amount + secret).
     *
     * @param  array<string, mixed>  $payload
     */
    public function expectedCallbackSign(array $payload): string
    {
        return sha1(
            (string) ($payload['uuid'] ?? '')
            .(string) ($payload['invoice_id'] ?? '')
            .(string) ($payload['amount'] ?? '')
            .(string) config('services.multicard.secret'),
        );
    }

    private function authed(): PendingRequest
    {
        return Http::asJson()->withToken($this->token());
    }

    /**
     * Seconds to cache the token for, derived from the gateway `expiry`
     * (minus a 60s safety margin). Falls back to 30 minutes.
     *
     * The gateway returns `expiry` as a naive datetime string in GMT+5, so it
     * MUST be parsed in that zone — parsing it as the app timezone (UTC) would
     * over-cache by 5h and serve an already-expired JWT ("Jwt is expired").
     */
    private function tokenTtl(mixed $expiry): int
    {
        if (is_string($expiry) && $expiry !== '') {
            try {
                $seconds = (int) (now()->diffInSeconds(Carbon::parse($expiry, '+05:00'), false) - 60);

                if ($seconds > 0) {
                    return $seconds;
                }
            } catch (\Throwable) {
                // fall through to default
            }
        }

        return 1800;
    }
}
