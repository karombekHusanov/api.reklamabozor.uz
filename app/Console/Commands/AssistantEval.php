<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantClient;
use App\Services\Assistant\AssistantPrompt;
use App\Services\Assistant\AssistantService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Compares candidate models on the two things this assistant is for: picking
 * the right service category from a real Uzbek request, and drafting an order
 * the client could send as-is — plus how long each answer takes, because a
 * chat that thinks for half a minute is not a feature.
 *
 *   php artisan assistant:eval
 *   php artisan assistant:eval --model=z-ai/glm-4.5-air:free --model=google/gemini-2.5-flash
 */
class AssistantEval extends Command
{
    protected $signature = 'assistant:eval {--model=* : Model ids to compare (defaults to ASSISTANT_EVAL_MODELS, then ASSISTANT_MODEL)}';

    protected $description = 'Score assistant models on Uzbek category detection, draft quality and latency';

    /**
     * Real-shaped requests with the category we expect. `expect` holds the
     * acceptable category names — matching is by name so the fixture survives
     * id changes between environments.
     *
     * @var array<int, array{prompt: string, expect: array<int, string>}>
     */
    private const CASES = [
        ['prompt' => 'Kafe ochilishi uchun 3x6 metrli banner chiqarib, Chilonzorga o\'rnatish kerak.', 'expect' => ['Tashqi reklama']],
        ['prompt' => 'Do\'konim uchun Instagram va Telegram yuritib beradigan odam kerak, oyiga 12 ta post.', 'expect' => ['Raqamli reklama', 'SMM']],
        ['prompt' => 'Yangi brend uchun logotip va uslub qo\'llanmasi kerak.', 'expect' => ['Logotip dizayni', 'Brending']],
        ['prompt' => 'Toshkent metrosida reklama joylashtirmoqchiman.', 'expect' => ['Transport reklamasi', 'Tashqi reklama']],
        ['prompt' => 'Mahsulotim uchun 30 soniyalik video rolik kerak.', 'expect' => ['Video ishlab chiqarish', 'Motion dizayn']],
        ['prompt' => 'Telekanalda reklama bermoqchiman, byudjet 20 mln.', 'expect' => ['Televidenie reklamasi']],
        ['prompt' => 'Ilovadan qanday foydalanaman? Buyurtma qanday beriladi?', 'expect' => []],
        ['prompt' => 'To\'lov qanday amalga oshadi va pulim qachon agentlikka o\'tadi?', 'expect' => []],
    ];

    public function handle(AssistantClient $client, AssistantPrompt $prompt, AssistantService $service): int
    {
        $models = $this->models();

        if ($models === []) {
            $this->error('No models to test. Pass --model= or set ASSISTANT_EVAL_MODELS.');

            return self::FAILURE;
        }

        $system = $prompt->build();
        $rows = [];

        foreach ($models as $model) {
            $this->line("Testing <info>{$model}</info> …");

            $latencies = [];
            $categoryHits = 0;
            $categoryCases = 0;
            $drafts = 0;
            $failures = 0;

            foreach (self::CASES as $case) {
                try {
                    $result = $client->chat([
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $case['prompt']],
                    ], $model);
                } catch (Throwable $e) {
                    $failures++;
                    $this->line('  <fg=red>x</> '.mb_substr($case['prompt'], 0, 40).'… — '.$e->getMessage());

                    continue;
                }

                $latencies[] = $result['latency_ms'];
                [, $draft] = $service->split($result['content']);

                if ($case['expect'] !== []) {
                    $categoryCases++;
                    $name = $draft['category_name'] ?? null;
                    $hit = $name !== null && in_array($name, $case['expect'], true);
                    if ($hit) {
                        $categoryHits++;
                    }
                    if ($draft !== null) {
                        $drafts++;
                    }
                    $this->line(sprintf('  %s %s → %s (%d ms)', $hit ? '<info>ok</info>' : '<comment>--</comment>', mb_substr($case['prompt'], 0, 34).'…', $name ?? 'kategoriyasiz', $result['latency_ms']));
                } else {
                    $this->line(sprintf('  <info>ok</info> %s → javob %d belgi (%d ms)', mb_substr($case['prompt'], 0, 34).'…', mb_strlen($result['content']), $result['latency_ms']));
                }
            }

            sort($latencies);
            $rows[] = [
                $model,
                $categoryCases > 0 ? sprintf('%d/%d', $categoryHits, $categoryCases) : '—',
                $categoryCases > 0 ? sprintf('%d/%d', $drafts, $categoryCases) : '—',
                $latencies === [] ? '—' : sprintf('%d ms', (int) (array_sum($latencies) / count($latencies))),
                $latencies === [] ? '—' : sprintf('%d ms', $latencies[(int) floor(count($latencies) * 0.9)] ?? end($latencies)),
                $failures,
            ];
        }

        $this->newLine();
        $this->table(['Model', 'Kategoriya', 'Qoralama', 'O\'rtacha', 'p90', 'Xato'], $rows);

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function models(): array
    {
        $fromOption = array_filter((array) $this->option('model'));
        if ($fromOption !== []) {
            return array_values($fromOption);
        }

        $configured = array_filter(array_map('trim', explode(',', (string) config('services.assistant.eval_models'))));

        return $configured !== [] ? $configured : array_filter([config('services.assistant.model')]);
    }
}
