<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Smoke-check Multicard callback config for local/dev (and prod before enable).
 * POSTs a dummy payload to MULTICARD_CALLBACK_URL — any HTTP response means the
 * tunnel/host is reachable; connection errors mean a dead URL (ERROR_CALLBACK).
 */
class MulticardDoctor extends Command
{
    protected $signature = 'multicard:doctor';

    protected $description = 'Validate Multicard callback URL config and reachability';

    public function handle(): int
    {
        $failed = false;

        $enabled = (bool) config('services.multicard.enabled');
        $baseUrl = rtrim((string) config('services.multicard.base_url'), '/');
        $callbackUrl = trim((string) config('services.multicard.callback_url'));
        $sign = strtolower((string) config('services.multicard.callback_sign', 'both'));
        $miniApp = trim((string) config('services.telegram.mini_app_url'));

        $this->line('Multicard doctor');
        $this->line('  enabled: '.($enabled ? 'true' : 'false'));
        $this->line('  base_url: '.($baseUrl !== '' ? $baseUrl : '(empty)'));
        $this->line('  callback_sign: '.$sign);
        $this->line('  mini_app_url: '.($miniApp !== '' ? $miniApp : '(empty)'));

        if ($enabled && $miniApp === '') {
            $this->warn('  TELEGRAM_MINI_APP_URL empty — invoice will omit return_url / return_error_url; user may stay on Multicard after pay.');
        } elseif ($miniApp !== '' && ! str_starts_with($miniApp, 'https://') && ! str_starts_with($miniApp, 'http://localhost') && ! str_starts_with($miniApp, 'http://127.')) {
            $this->warn('  mini_app_url should be https:// in real Telegram (got: '.$miniApp.')');
        }

        if ($callbackUrl === '') {
            $this->error('  callback_url: EMPTY — set MULTICARD_CALLBACK_URL to a public HTTPS URL');
            $failed = true;
        } else {
            $this->line('  callback_url: '.$callbackUrl);

            if (! str_starts_with($callbackUrl, 'https://')) {
                $this->error('  callback_url must be https:// (Multicard rejects plain http)');
                $failed = true;
            }

            if (! str_contains($callbackUrl, '/payment/multicard/callback')) {
                $this->warn('  callback_url path should end with /api/v1/payment/multicard/callback');
            }
        }

        if (! in_array($sign, ['sha1', 'md5', 'both'], true)) {
            $this->error('  callback_sign must be sha1|md5|both (got: '.$sign.')');
            $failed = true;
        }

        if ($callbackUrl === '') {
            return self::FAILURE;
        }

        $this->line('  probing callback (POST dummy)…');

        try {
            $response = Http::timeout(5)
                ->asJson()
                ->post($callbackUrl, [
                    'uuid' => 'doctor-probe',
                    'invoice_id' => 'doctor',
                    'amount' => 0,
                    'status' => 'draft',
                    'sign' => 'doctor',
                ]);

            $this->info("  reachable: HTTP {$response->status()} (403/200 expected for dummy payload)");
        } catch (Throwable $e) {
            $this->error('  unreachable: '.$e->getMessage());
            $this->line('  Fix: start cloudflared/ngrok → :8000, update MULTICARD_CALLBACK_URL + Multicard cabinet.');
            $failed = true;
        }

        if ($failed) {
            $this->newLine();
            $this->error('Doctor failed — dead or misconfigured callback will cause Multicard ERROR_CALLBACK.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Doctor OK.');

        return self::SUCCESS;
    }
}
