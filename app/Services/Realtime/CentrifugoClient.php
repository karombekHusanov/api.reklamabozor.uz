<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Centrifugo server HTTP API (v6: `POST /api/{method}`,
 * `X-API-Key` header): publish / broadcast + presence_stats.
 */
class CentrifugoClient
{
    /** @param  array<string, mixed>  $data */
    public function publish(string $channel, array $data): void
    {
        $this->call('publish', ['channel' => $channel, 'data' => $data]);
    }

    /**
     * Same payload to several channels in one call (chat → both participants).
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $data
     */
    public function broadcast(array $channels, array $data): void
    {
        $this->call('broadcast', ['channels' => array_values($channels), 'data' => $data]);
    }

    /** Unique users currently subscribed to a presence-enabled channel. */
    public function presenceUsers(string $channel): int
    {
        $result = $this->call('presence_stats', ['channel' => $channel]);

        return (int) ($result['num_users'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        $response = Http::asJson()
            ->timeout((int) config('realtime.timeout_seconds'))
            ->withHeaders(['X-API-Key' => (string) config('realtime.api_key')])
            ->post(rtrim((string) config('realtime.api_url'), '/').'/'.$method, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Centrifugo {$method} failed: {$response->status()} {$response->body()}");
        }

        $error = $response->json('error');
        if (is_array($error)) {
            throw new RuntimeException("Centrifugo {$method} error {$error['code']}: {$error['message']}");
        }

        return (array) $response->json('result', []);
    }
}
