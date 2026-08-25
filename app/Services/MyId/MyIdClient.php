<?php

namespace App\Services\MyId;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper over the MyID WebSDK backend (docs.myid.uz).
 *
 * Auth is a short-lived bearer token from the OAuth2 client-credentials grant,
 * cached until just before it expires. All identity-bearing calls go through
 * this class so credentials never leak to the frontend.
 *
 * NOTE: exact endpoint shapes follow the public docs; they can only be
 * validated end-to-end once real MyID credentials + scopes are provisioned.
 * Until then the whole flow is gated by config('services.myid.enabled').
 */
class MyIdClient
{
    private const TOKEN_CACHE_KEY = 'myid:token';

    private function baseUrl(): string
    {
        return rtrim((string) config('services.myid.base_url'), '/');
    }

    /**
     * Obtain (and cache) a backend bearer token via the client-credentials grant.
     */
    public function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->post($this->baseUrl().'/api/v1/oauth2/access-token', [
            'grant_type' => 'client_credentials',
            'client_id' => (string) config('services.myid.client_id'),
            'client_secret' => (string) config('services.myid.client_secret'),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('MyID auth failed: '.$response->body());
        }

        // Cache slightly short of the stated lifetime (fallback 30 min).
        $expiresIn = (int) ($response->json('expires_in') ?? 1800);
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /**
     * Create a WebSDK session. Returns the `session_id` the mini app iframe uses.
     *
     * @param  array<string, mixed>  $payload  e.g. max_retries, external_id, ip_address
     * @return array<string, mixed>
     */
    public function createWebSession(array $payload): array
    {
        $response = $this->authed()->post($this->baseUrl().'/api/v1/web/sessions', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('MyID session creation failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $data;
    }

    /**
     * Build the OAuth2 authorization URL for the redirect (fallback) flow. The
     * frontend opens this via WebApp.openLink when the camera iframe is blocked.
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $query = http_build_query([
            'client_id' => (string) config('services.myid.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => (string) config('services.myid.scope'),
            'method' => 'strong',
            'state' => $state,
        ]);

        return $this->baseUrl().'/api/v1/oauth2/authorization?'.$query;
    }

    /**
     * Exchange the one-time auth_code (from the iframe or redirect) for a user
     * token. In the redirect flow `redirect_uri` must match the one used to
     * obtain the code.
     *
     * @return array<string, mixed>
     */
    public function exchangeAuthCode(string $code, ?string $redirectUri = null): array
    {
        $response = Http::asForm()->post($this->baseUrl().'/api/v1/oauth2/access-token', array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => (string) config('services.myid.client_id'),
            'client_secret' => (string) config('services.myid.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'method' => 'strong',
            'scope' => (string) config('services.myid.scope'),
        ]));

        if (! $response->successful()) {
            throw new RuntimeException('MyID code exchange failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $data;
    }

    /**
     * Fetch the verified government profile with a user access token.
     *
     * @return array<string, mixed>
     */
    public function fetchUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($this->baseUrl().'/api/v1/users/me');

        if (! $response->successful()) {
            throw new RuntimeException('MyID user fetch failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return $data;
    }

    private function authed(): PendingRequest
    {
        return Http::asJson()->withToken($this->token());
    }
}
