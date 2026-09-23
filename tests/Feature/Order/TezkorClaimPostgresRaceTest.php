<?php

namespace Tests\Feature\Order;

use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * The whole suite runs on sqlite (phpunit.xml), whose "lockForUpdate" is a
 * no-op single-writer lock — it cannot prove OfferService::submitOffer()
 * actually serialises concurrent Tezkor claims under real OS-level
 * concurrency. This test drives genuinely simultaneous requests against a
 * real, disposable Postgres database, each from its OWN OS process (see
 * tests/Support/pgsql_race_claim.php) — separate PHP boots, separate DB
 * connections, exactly like separate PHP-FPM workers in production. (An
 * earlier version forked the running PHPUnit process instead; that shares
 * the test runner's own state across the fork and turned out to be fragile
 * here — this harness's own shutdown reporting re-fires in every forked
 * copy. Spawning real subprocesses avoids that entirely.)
 *
 * Opt-in only (RUN_PGSQL_RACE_TEST=1) and skipped whenever a local Postgres
 * (same host/port/credentials as .env's DB_* — see CLAUDE.md "Local Setup")
 * is unreachable, so the default `php artisan test` run — and CI, which has
 * no Postgres service — never depends on it. It never touches the project's
 * real database: everything runs against its own `adspace_pgsql_race_test`
 * database, dropped-and-rebuilt (migrate:fresh) every run.
 *
 * Run locally:
 *   RUN_PGSQL_RACE_TEST=1 php artisan test tests/Feature/Order/TezkorClaimPostgresRaceTest.php
 */
class TezkorClaimPostgresRaceTest extends TestCase
{
    private const CONNECTION = 'pgsql_race';

    private const DATABASE = 'adspace_pgsql_race_test';

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('RUN_PGSQL_RACE_TEST', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set RUN_PGSQL_RACE_TEST=1 (with a local Postgres reachable via .env DB_HOST/PORT/USERNAME/PASSWORD) to run this test.');
        }

        try {
            $this->prepareRaceDatabase();
        } catch (Throwable $e) {
            $this->markTestSkipped('Postgres is not reachable for the race test: '.$e->getMessage());
        }
    }

    public function test_only_one_agent_wins_a_concurrent_claim(): void
    {
        $order = Order::factory()->status(OrderStatus::New)->create([
            'category_id' => null,
            'route' => OrderRoute::Tezkor,
        ]);

        $agentCount = 6;
        $tokens = [];

        for ($i = 0; $i < $agentCount; $i++) {
            $user = User::factory()->create();
            AgentProfile::factory()->for($user)->approved()->create();
            $tokens[$user->id] = $user->createToken('race')->plainTextToken;
        }

        $resultDir = sys_get_temp_dir().'/pgsql_race_'.uniqid();
        mkdir($resultDir);

        $processes = [];

        // Launch all N first — proc_open starts each in the background and
        // returns immediately, so by the time this loop ends every one of
        // them is already an independent, running OS process racing the
        // others for the same order row.
        foreach ($tokens as $agentId => $token) {
            $processes[$agentId] = $this->launchClaimProcess($order->id, $token, $resultDir, $agentId);
        }

        $exitCodes = [];
        foreach ($processes as $agentId => $process) {
            // proc_close() blocks only for THIS handle; the others keep
            // running — it does not serialise their execution.
            $exitCodes[$agentId] = proc_close($process);
        }

        $results = collect($tokens)->keys()->map(function (int $agentId) use ($resultDir, $exitCodes) {
            $raw = @file_get_contents("{$resultDir}/{$agentId}.json");

            return array_merge(
                ['agent_id' => $agentId, 'exit_code' => $exitCodes[$agentId]],
                json_decode((string) $raw, true) ?? ['status' => null, 'body' => null],
            );
        });

        $this->cleanupResultDir($resultDir);

        $winners = $results->where('status', 201);
        $losers = $results->where('status', 409);

        $this->assertCount($agentCount, $results, 'every subprocess must report a result');
        $this->assertCount(1, $winners, 'exactly one agent must win the claim: '.$results->toJson());
        $this->assertCount($agentCount - 1, $losers, 'every other agent must see 409 (already taken): '.$results->toJson());

        $fresh = Order::query()->find($order->id);
        $this->assertSame((int) $winners->first()['agent_id'], $fresh->claimed_agent_id);
        $this->assertSame(1, $fresh->offers()->count(), 'only the winning claim may have created an offer row');
        $this->assertSame(OrderStatus::OffersSent, $fresh->status);
    }

    /** @return resource */
    private function launchClaimProcess(int $orderId, string $token, string $resultDir, int $agentId)
    {
        $script = base_path('tests/Support/pgsql_race_claim.php');
        $outputFile = "{$resultDir}/{$agentId}.json";

        $env = array_merge((array) getenv(), [
            // Point the subprocess's own, independent boot at the same
            // disposable database, through the ordinary "pgsql" connection.
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => self::DATABASE,
            // Fail instantly instead of hitting the real Telegram API — the
            // notifier's own try/catch swallows this either way.
            'TELEGRAM_API_URL' => 'http://127.0.0.1:9',
        ]);

        $process = proc_open(
            [PHP_BINARY, $script, (string) $orderId, $token, $outputFile],
            [1 => ['file', "{$resultDir}/{$agentId}.out.log", 'w'], 2 => ['file', "{$resultDir}/{$agentId}.err.log", 'w']],
            $pipes,
            base_path(),
            $env,
        );

        if ($process === false) {
            $this->fail('proc_open failed to launch the claim probe.');
        }

        return $process;
    }

    private function cleanupResultDir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function prepareRaceDatabase(): void
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '5432');
        $username = env('DB_USERNAME', 'postgres');
        $password = env('DB_PASSWORD', '');

        $admin = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);
        $exists = (bool) $admin->query('SELECT 1 FROM pg_database WHERE datname = '.$admin->quote(self::DATABASE))->fetchColumn();

        if (! $exists) {
            $admin->exec('CREATE DATABASE "'.self::DATABASE.'"');
        }

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => self::DATABASE,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]]);
        config(['database.default' => self::CONNECTION]);

        // Disposable database: wipe and rebuild the schema every run rather
        // than trust leftovers from a previous (possibly stale) run.
        Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]);
    }
}
