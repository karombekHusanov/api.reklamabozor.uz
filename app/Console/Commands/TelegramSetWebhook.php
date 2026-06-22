<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {url? : Full public URL of /api/v1/telegram/webhook}';

    protected $description = 'Register the bot webhook with Telegram (uses TELEGRAM_WEBHOOK_SECRET).';

    public function handle(TelegramBotService $bot): int
    {
        $url = $this->argument('url')
            ?? rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook';

        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not set in .env.');

            return self::FAILURE;
        }

        $this->info("Registering webhook: {$url}");

        $response = $bot->setWebhook($url, $secret);

        if ($response->successful() && $response->json('ok') === true) {
            $this->info('Webhook registered successfully.');

            return self::SUCCESS;
        }

        $this->error('Failed: '.$response->body());

        return self::FAILURE;
    }
}
