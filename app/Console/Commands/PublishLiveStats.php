<?php

namespace App\Console\Commands;

use App\Services\Realtime\CentrifugoClient;
use App\Services\Realtime\LiveStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishLiveStats extends Command
{
    protected $signature = 'stats:publish-live';

    protected $description = 'Snapshot live stats (Centrifugo presence) and push them to every connected mini app';

    public function handle(LiveStatsService $stats, CentrifugoClient $centrifugo): int
    {
        if (! LiveStatsService::enabled()) {
            return self::SUCCESS;
        }

        $snapshot = $stats->snapshot();

        try {
            $centrifugo->publish((string) config('realtime.channels.pulse'), [
                'type' => 'stats',
                'stats' => $snapshot,
            ]);
        } catch (Throwable $e) {
            Log::warning('realtime.publish_failed', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
