<?php

namespace App\Services\Assistant;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Runs one assistant turn: builds the prompt, calls the provider, and turns the
 * reply into a visible message plus an optional, server-validated order draft.
 *
 * Everything the model returns is untrusted: the draft is re-checked against
 * the real category table before it reaches the client, and the raw JSON block
 * is stripped from the text the user sees.
 */
class AssistantService
{
    /** Model output is never longer than this in the reply we forward. */
    private const MAX_REPLY_CHARS = 2000;

    public function __construct(
        private readonly AssistantClient $client,
        private readonly AssistantPrompt $prompt,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, draft: array{category_id: int|null, category_name: string|null, title: string, description: string}|null, model: string, latency_ms: int}
     */
    public function reply(User $user, array $history): array
    {
        $this->assertWithinDailyLimit($user);

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->prompt->build()]],
            $history,
        );

        $result = $this->client->chat($messages);

        [$text, $draft] = $this->split($result['content']);

        return [
            'reply' => mb_substr($text, 0, self::MAX_REPLY_CHARS),
            'draft' => $draft,
            'model' => $result['model'],
            'latency_ms' => $result['latency_ms'],
        ];
    }

    /**
     * Streaming turn. Deltas are forwarded as they arrive, except that anything
     * from the opening ``` fence onwards is withheld — the JSON draft is for
     * the app, never for the reader. Returns the same shape as reply().
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  callable(string): void  $onDelta
     * @return array{reply: string, draft: array{category_id: int|null, category_name: string|null, title: string, description: string}|null}
     */
    public function streamReply(User $user, array $history, callable $onDelta): array
    {
        $this->assertWithinDailyLimit($user);

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->prompt->build()]],
            $history,
        );

        $accumulated = '';
        $sent = 0;

        $full = $this->client->stream($messages, function (string $chunk) use (&$accumulated, &$sent, $onDelta): void {
            $accumulated .= $chunk;

            $fence = mb_strpos($accumulated, '```');
            $visible = $fence === false ? $accumulated : mb_substr($accumulated, 0, $fence);

            if (mb_strlen($visible) > $sent) {
                $onDelta(mb_substr($visible, $sent));
                $sent = mb_strlen($visible);
            }
        });

        [$text, $draft] = $this->split($full);

        return [
            'reply' => mb_substr($text, 0, self::MAX_REPLY_CHARS),
            'draft' => $draft,
        ];
    }

    /**
     * Split the model's answer into the visible text and a validated draft.
     *
     * @return array{0: string, 1: array{category_id: int|null, category_name: string|null, title: string, description: string}|null}
     */
    public function split(string $content): array
    {
        if (! preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            return [trim($content), null];
        }

        // Removing the block mid-answer leaves a gap — collapse it so the
        // bubble does not show a hole where the JSON used to be.
        $text = trim(preg_replace('/\n{3,}/', "\n\n", str_replace($matches[0], '', $content)) ?? '');
        $decoded = json_decode($matches[1], true);

        if (! is_array($decoded)) {
            return [$text, null];
        }

        $description = trim((string) ($decoded['description'] ?? ''));
        if ($description === '') {
            return [$text, null];
        }

        $category = $this->prompt->resolveCategory($decoded['category_id'] ?? null);

        return [$text, [
            'category_id' => $category?->id,
            'category_name' => $category?->name_uz,
            'title' => mb_substr(trim((string) ($decoded['title'] ?? '')), 0, 200),
            'description' => mb_substr($description, 0, 2000),
        ]];
    }

    /**
     * Per-user daily cap on top of the route's per-minute throttle: the
     * provider bills (or rate-limits) us, so one account cannot drain it.
     */
    private function assertWithinDailyLimit(User $user): void
    {
        $limit = (int) config('services.assistant.daily_limit');
        if ($limit <= 0) {
            return;
        }

        $key = 'assistant:usage:'.$user->id.':'.now()->format('Y-m-d');
        $used = (int) Cache::get($key, 0);

        if ($used >= $limit) {
            throw ValidationException::withMessages([
                'messages' => ['Bugungi limit tugadi. Ertaga yana urinib ko\'ring.'],
            ]);
        }

        Cache::put($key, $used + 1, now()->endOfDay());
    }

    public static function assertEnabled(): void
    {
        if (! config('services.assistant.enabled')) {
            throw new RuntimeException('Assistant is disabled.');
        }
    }
}
