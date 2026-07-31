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
     * Cancel an unpaid hosted-checkout invoice. No-op-ish on already-paid
     * invoices (gateway returns 400) — callers treat failures as best-effort.
     */
    public function cancelInvoice(string $uuid): void
    {
        $response = $this->authed()->delete($this->baseUrl().'/payment/invoice/'.$uuid);

        if (! $response->successful()) {
            throw new RuntimeException('Multicard invoice cancel failed: '.$response->body());
        }
    }

    /**
     * Full refund of a settled payment (docs: DELETE /payment/{uuid}).
     * Gateway moves the payment to status `revert`.
     *
     * @return array<string, mixed>
     */
    public function refundPayment(string $uuid): array
    {
        $response = $this->authed()
            ->withBody('{}', 'application/json')
            ->delete($this->baseUrl().'/payment/'.$uuid);

        if (! $response->successful()) {
            throw new RuntimeException('Multicard refund failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? $response->json() ?? [];

        return is_array($data) ? $data : [];
    }

    // --- Card payouts (agent cash-out): hosted bind form → credit → OTP ---

    /**
     * Open a hosted card-entry form. The cardholder types their Uzcard/Humo
     * number on Multicard's page (never on ours), so we stay out of PCI scope.
     * Returns { session_id, form_url }.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bindCardForm(array $payload): array
    {
        $response = $this->authed()->post($this->baseUrl().'/payment/card/bind', $payload);

        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('Multicard card-bind form failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Poll a bind session for its result. Once the card is entered the response
     * carries `card_token` and `status` = active. Returns the `data` payload.
     *
     * @return array<string, mixed>
     */
    public function getCardBinding(string $sessionId): array
    {
        $response = $this->authed()->get($this->baseUrl().'/payment/card/bind/'.$sessionId);

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Create a payout (credit) to a bound card. With `confirmable` the gateway
     * returns a draft transaction that must be confirmed with an OTP. Returns
     * the `data` payload (uuid, status, card_pan, ps, ...).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCardPayout(array $payload): array
    {
        $response = $this->authed()->post($this->baseUrl().'/payment/credit', $payload);

        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('Multicard credit failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Confirm a payout with the OTP the cardholder received. Returns the `data`
     * payload with the final status (success | error).
     *
     * @return array<string, mixed>
     */
    public function confirmCardPayout(string $uuid, string $otp): array
    {
        $response = $this->authed()->put($this->baseUrl().'/payment/credit/'.$uuid, ['otp' => $otp]);

        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('Multicard credit confirm failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Discard a one-time card token after a payout so no card reference is kept.
     * Best-effort — failures here must not break the withdrawal.
     */
    public function annulCardToken(string $token): void
    {
        try {
            $this->authed()->delete($this->baseUrl().'/payment/card/'.$token);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Verify a Multicard callback signature.
     *
     * Docs (`callback-webhooks`): sha1(uuid + invoice_id + amount + secret).
     * Legacy (older stand):      md5(store_id + invoice_id + amount + secret).
     *
     * Mode is `services.multicard.callback_sign`: sha1 | md5 | both (default both).
     * Callers must already have confirmed invoice_id / amount match our Payment.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, alg: string|null} alg = which algorithm matched (for logs)
     */
    public function verifyCallbackSign(array $payload, string $uuid, string $invoiceId, int $amount): array
    {
        $provided = strtolower(trim((string) ($payload['sign'] ?? '')));

        if ($provided === '') {
            return ['ok' => false, 'alg' => null];
        }

        $mode = strtolower((string) config('services.multicard.callback_sign', 'both'));
        $candidates = match ($mode) {
            'md5' => ['md5'],
            'sha1' => ['sha1'],
            default => ['sha1', 'md5'], // both / unknown → accept either
        };

        foreach ($candidates as $alg) {
            $expected = $this->callbackSign($alg, $uuid, $invoiceId, $amount);

            if (hash_equals($expected, $provided)) {
                return ['ok' => true, 'alg' => $alg];
            }
        }

        return ['ok' => false, 'alg' => null];
    }

    /**
     * Compute a callback signature for the given algorithm.
     *
     * @param  'sha1'|'md5'  $alg
     */
    public function callbackSign(string $alg, string $uuid, string $invoiceId, int $amount): string
    {
        $secret = (string) config('services.multicard.secret');

        return match ($alg) {
            'md5' => md5(
                (string) config('services.multicard.store_id')
                .$invoiceId
                .(string) $amount
                .$secret,
            ),
            default => sha1($uuid.$invoiceId.(string) $amount.$secret),
        };
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
