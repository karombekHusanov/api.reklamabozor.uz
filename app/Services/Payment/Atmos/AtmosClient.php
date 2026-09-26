<?php

namespace App\Services\Payment\Atmos;

use App\Services\Payment\Gateway\CardPaymentException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the ATMOS API gateway: client-credentials token (cached,
 * refreshed on 401), the hosted-checkout invoice endpoints, the merchant
 * card endpoints (create → pre-apply → apply) used by the in-app card form
 * and the card binding endpoints (bind-card init → confirm, remove-card).
 */
class AtmosClient
{
    private const TOKEN_CACHE_KEY = 'atmos.access_token';

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{payment_id: string, token: string, url: string}
     */
    public function createInvoice(string $requestId, string $account, int $amountTiyin, string $successUrl, array $items, int $ttlMinutes): array
    {
        $data = $this->post('/checkout/invoice/create', [
            'request_id' => $requestId,
            'store_id' => (int) config('atmos.store_id'),
            'expiration_time' => $ttlMinutes,
            'account' => $account,
            'amount' => $amountTiyin,
            'success_url' => $successUrl,
            'items' => $items,
        ]);

        if (empty($data['payment_id']) || empty($data['url'])) {
            throw new RuntimeException('ATMOS invoice response is missing payment_id/url.');
        }

        return [
            'payment_id' => (string) $data['payment_id'],
            'token' => (string) ($data['token'] ?? ''),
            'url' => (string) $data['url'],
        ];
    }

    /** @return array<string, mixed> */
    public function getInvoice(string $paymentId): array
    {
        return $this->post('/checkout/invoice/get', ['payment_id' => (int) $paymentId]);
    }

    /**
     * Open a merchant transaction for the in-app card form.
     *
     * @param  list<array<string, mixed>>  $ofdItems
     */
    public function merchantCreate(string $account, int $amountTiyin, array $ofdItems = []): string
    {
        $data = $this->merchantPost('/merchant/pay/create', array_filter([
            'store_id' => (int) config('atmos.store_id'),
            'account' => $account,
            'amount' => $amountTiyin,
            'lang' => app()->getLocale() === 'en' ? 'en' : (app()->getLocale() === 'ru' ? 'ru' : 'uz'),
            'ofd_items' => $ofdItems ?: null,
        ], fn ($v) => $v !== null));

        if (empty($data['transaction_id'])) {
            throw new RuntimeException('ATMOS merchant/pay/create response is missing transaction_id.');
        }

        return (string) $data['transaction_id'];
    }

    /**
     * Attach the card to the transaction; ATMOS texts an SMS code to the
     * cardholder. `$expiryYymm` is year + month ("2801" = 2028-01).
     */
    public function merchantPreApply(string $transactionId, string $cardNumber, string $expiryYymm): void
    {
        $this->merchantPost('/merchant/pay/pre-apply', [
            'store_id' => (int) config('atmos.store_id'),
            'transaction_id' => (int) $transactionId,
            'card_number' => $cardNumber,
            'expiry' => $expiryYymm,
        ]);
    }

    /** Attach a bound card to the transaction by its token — no SMS is sent. */
    public function merchantPreApplyToken(string $transactionId, string $cardToken): void
    {
        $this->merchantPost('/merchant/pay/pre-apply', [
            'store_id' => (int) config('atmos.store_id'),
            'transaction_id' => (int) $transactionId,
            'card_token' => $cardToken,
        ]);
    }

    /**
     * Start binding a card; ATMOS texts an SMS code to the cardholder.
     * Returns the binding transaction id.
     */
    public function bindCardInit(string $cardNumber, string $expiryYymm): string
    {
        $data = $this->merchantPost('/partner/bind-card/init', [
            'card_number' => $cardNumber,
            'expiry' => $expiryYymm,
        ]);

        if (empty($data['transaction_id'])) {
            throw new RuntimeException('ATMOS bind-card/init response is missing transaction_id.');
        }

        return (string) $data['transaction_id'];
    }

    /** @return array<string, mixed> The `data` block (card_id, pan, card_token, …). */
    public function bindCardConfirm(string $transactionId, string $otp): array
    {
        $data = $this->merchantPost('/partner/bind-card/confirm', [
            'transaction_id' => (int) $transactionId,
            'otp' => $otp,
        ]);

        return (array) ($data['data'] ?? []);
    }

    public function removeCard(string $cardId, string $cardToken): void
    {
        $this->merchantPost('/partner/remove-card', [
            'id' => (int) $cardId,
            'token' => $cardToken,
        ]);
    }

    /** @return array<string, mixed> The `store_transaction` block. */
    public function merchantApply(string $transactionId, string $otp): array
    {
        $data = $this->merchantPost('/merchant/pay/apply', [
            'store_id' => (int) config('atmos.store_id'),
            'transaction_id' => (int) $transactionId,
            'otp' => $otp,
        ]);

        return (array) ($data['store_transaction'] ?? []);
    }

    /** @return array<string, mixed> The `store_transaction` block. */
    public function merchantGet(string $transactionId): array
    {
        $data = $this->merchantPost('/merchant/pay/get', [
            'store_id' => (int) config('atmos.store_id'),
            'transaction_id' => (int) $transactionId,
        ]);

        return (array) ($data['store_transaction'] ?? []);
    }

    /**
     * Merchant endpoints report errors in `result.code`. A non-OK code is a
     * business error the customer can act on (declined card, wrong code), so
     * it is raised as a {@see CardPaymentException} carrying ATMOS's text.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function merchantPost(string $path, array $payload): array
    {
        $json = $this->send($path, $payload);

        $code = $json['result']['code'] ?? null;

        if ($code !== null && (string) $code !== 'OK') {
            throw new CardPaymentException((string) ($json['result']['description'] ?? $code));
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $json = $this->send($path, $payload);

        $code = $json['status']['code'] ?? null;

        if ($code !== null && ! in_array((string) $code, ['0', 'OK'], true)) {
            throw new RuntimeException("ATMOS {$path} returned status {$code}: ".($json['status']['description'] ?? ''));
        }

        return $json;
    }

    /**
     * Authenticated POST with one token refresh on 401. Never logs the payload
     * (it may hold a card number).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $path, array $payload): array
    {
        $this->assertConfigured();

        $response = $this->http()->post($path, $payload);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->http()->post($path, $payload);
        }

        if ($response->failed()) {
            throw new RuntimeException("ATMOS {$path} failed with HTTP {$response->status()}.");
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('atmos.base_url'))
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('atmos.timeout'));
    }

    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(50), function (): string {
            $response = Http::baseUrl((string) config('atmos.base_url'))
                ->withBasicAuth((string) config('atmos.consumer_key'), (string) config('atmos.consumer_secret'))
                ->asForm()
                ->timeout((int) config('atmos.timeout'))
                ->post('/token', ['grant_type' => 'client_credentials']);

            $token = $response->json('access_token');

            if ($response->failed() || ! is_string($token) || $token === '') {
                throw new RuntimeException('ATMOS token request failed.');
            }

            return $token;
        });
    }

    private function assertConfigured(): void
    {
        foreach (['consumer_key', 'consumer_secret', 'store_id'] as $key) {
            if (empty(config("atmos.$key"))) {
                throw new RuntimeException("ATMOS is not configured (atmos.$key).");
            }
        }
    }
}
