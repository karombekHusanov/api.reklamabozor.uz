<?php

namespace App\Console\Commands;

use App\Services\Telegram\AdminNotifier;
use Illuminate\Console\Command;

class TelegramOpsPing extends Command
{
    protected $signature = 'telegram:ops-ping';

    protected $description = 'Send a connectivity test message to the admin ops group (TELEGRAM_ADMIN_CHAT_ID)';

    public function handle(AdminNotifier $notifier): int
    {
        if ((string) config('services.telegram.admin_chat_id') === '') {
            $this->error('TELEGRAM_ADMIN_CHAT_ID is not configured.');

            return self::FAILURE;
        }

        $notifier->ping('✅ Ops-guruh ulandi — Reklama Bozor bot marketplace eventlarini shu yerga yozadi.');
        $this->info('Ping sent (check the group).');

        return self::SUCCESS;
    }
}
