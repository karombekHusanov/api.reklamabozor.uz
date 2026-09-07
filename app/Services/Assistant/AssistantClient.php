<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for any OpenAI-compatible /chat/completions endpoint
 * (OpenRouter, Gemini compat, DeepSeek, Zhipu/GLM, Groq, ...). Provider choice
 * is config, not code. The API key lives only here — it is never sent to,
 * or derivable from, the mini app.
 */
class AssistantClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, latency_ms: int, usage: array<string, mixed>}
     */
    public function chat(array $messages, ?string $model = null): array
    {
        $config = config('services.assistant');
        $key = (string) ($config['api_key'] ?? '');

        if ($key === '') {
            throw new RuntimeException('Assistant API key is not configured.');
        }

        $startedAt = microtime(true);

        $response = Http::withToken($key)
            ->timeout((int) $config['timeout'])
            // Fail fast on a dead endpoint instead of burning the whole budget.
            ->connectTimeout(5)
            // Retry only when the connection never landed — retrying a slow
            // answer would double the wait, and a chat that hangs is worse
            // than a chat that fails.
            ->retry(2, 200, fn (\Throwable $e): bool => $e instanceof ConnectionException, throw: false)
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', [
                'model' => $model ?? $config['model'],
                'messages' => $messages,
                'max_tokens' => (int) $config['max_tokens'],
                'temperature' => (float) $config['temperature'],
            ]);

        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw new RuntimeException('Assistant request failed with status '.$response->status());
        }

        $payload = $response->json();
        $content = data_get($payload, 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Assistant returned an empty response.');
        }

        return [
            'content' => trim($content),
            'model' => (string) data_get($payload, 'model', $model ?? $config['model']),
            'latency_ms' => $latency,
            'usage' => (array) data_get($payload, 'usage', []),
        ];
    }

    /**
     * Same request with `stream: true`: each delta is handed to $onDelta as it
     * arrives and the full text is returned at the end. Streaming is what makes
     * the chat feel fast — the first words land in about a second even when the
     * whole answer takes several.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     */
    public function stream(array $messages, callable $onDelta, ?string $model = null): string
    {
        $config = config('services.assistant');
        $key = (string) ($config['api_key'] ?? '');

        if ($key === '') {
            throw new RuntimeException('Assistant API key is not configured.');
        }

        $response = Http::withToken($key)
            ->timeout((int) $config['timeout'])
            ->connectTimeout(5)
            ->withOptions(['stream' => true])
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', [
                'model' => $model ?? $config['model'],
                'messages' => $messages,
                'max_tokens' => (int) $config['max_tokens'],
                'temperature' => (float) $config['temperature'],
                'stream' => true,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Assistant request failed with status '.$response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $full = '';

        while (! $body->eof()) {
            $buffer .= $body->read(2048);

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $payload = trim(substr($line, 5));

                if ($payload === '[DONE]') {
                    return $full;
                }

                $delta = data_get(json_decode($payload, true), 'choices.0.delta.content');

                if (is_string($delta) && $delta !== '') {
                    $full .= $delta;
                    $onDelta($delta);
                }
            }
        }

        if (trim($full) === '') {
            throw new RuntimeException('Assistant returned an empty response.');
        }

        return $full;
    }
}
