<?php

namespace App\Services\Assistant;

use Throwable;

/**
 * Picks the best service category for a free-text order description.
 *
 * Runs synchronously inside order creation, so it is strictly best-effort:
 * disabled assistant, missing key, timeout, HTTP error or an unusable answer
 * all yield null and the order simply stays category-less. It does not touch
 * the per-user chat daily limit.
 */
class CategoryClassifier
{
    public function __construct(
        private readonly AssistantClient $client,
        private readonly AssistantPrompt $prompt,
    ) {}

    public function classify(string $description): ?int
    {
        if (! config('services.assistant.enabled') || trim($description) === '') {
            return null;
        }

        $categories = $this->prompt->categories();
        if ($categories === []) {
            return null;
        }

        try {
            $result = $this->client->chat(
                [
                    ['role' => 'system', 'content' => $this->systemPrompt($categories)],
                    ['role' => 'user', 'content' => mb_substr($description, 0, 2000)],
                ],
                timeout: max(1, (int) config('services.assistant.classify_timeout', 6)),
                maxTokens: 16,
                temperature: 0.0,
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $this->parse($result['content']);
    }

    /**
     * Strict parse: the whole answer must be a single integer (or "null").
     * The id is then re-checked against the active category table.
     */
    private function parse(string $content): ?int
    {
        $answer = trim($content, " \t\n\r\0\x0B`\"'.");

        if (! ctype_digit($answer)) {
            return null;
        }

        return $this->prompt->resolveCategory($answer)?->id;
    }

    /** @param  array<int, array{id: int, name: string, type: string}>  $categories */
    private function systemPrompt(array $categories): string
    {
        $lines = array_map(
            fn (array $category): string => "{$category['id']}: {$category['name']} ({$category['type']})",
            $categories,
        );

        return "Sen reklama buyurtmalarini tasniflaysan. Foydalanuvchi matniga eng mos xizmat turining id sini tanla.\n"
            ."Ish BAJARILISHI kerak bo'lsa (chop etish, o'rnatish, joylashtirish, yuritish) — `agent` turi; faqat maket/dizayn chizilishi kerak bo'lsa — `designer` turi.\n"
            ."Ro'yxat:\n".implode("\n", $lines)."\n"
            .'Javobing FAQAT bitta butun son (id) yoki `null` bo\'lsin. Boshqa hech narsa yozma. Ishonching komil bo\'lmasa `null`.';
    }
}
