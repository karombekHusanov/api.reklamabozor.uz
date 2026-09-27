<?php

namespace App\Services\Realtime;

use App\Enums\CategoryType;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public "live pulse" numbers for the home screen.
 *
 * Realtime on: online counts come from Centrifugo presence (connected users,
 * held in memory), `stats:publish-live` pushes a snapshot to every client and
 * GET /stats/live serves the same snapshot. Realtime off: the legacy Sanctum
 * `last_used_at` heuristic (active in the last 10 minutes).
 */
class LiveStatsService
{
    public const SNAPSHOT_KEY = 'stats:live:snapshot';

    private const LEGACY_CACHE_KEY = 'stats:live';

    private const ONLINE_WINDOW_MINUTES = 10;

    public function __construct(private readonly CentrifugoClient $centrifugo) {}

    public static function enabled(): bool
    {
        return (bool) config('realtime.enabled');
    }

    /** @return array<string, int|null> */
    public function current(): array
    {
        if (! self::enabled()) {
            return Cache::remember(self::LEGACY_CACHE_KEY, 15, fn (): array => $this->compute());
        }

        return Cache::get(self::SNAPSHOT_KEY) ?? $this->snapshot();
    }

    /** Fresh numbers, cached as the shared snapshot. */
    public function snapshot(): array
    {
        $stats = $this->compute();
        Cache::put(self::SNAPSHOT_KEY, $stats, (int) config('realtime.snapshot_ttl_seconds'));

        return $stats;
    }

    /** @return array<string, int|null> */
    public function compute(): array
    {
        [$usersOnline, $agentsOnline] = self::enabled()
            ? $this->presenceCounts()
            : $this->legacyOnlineCounts();

        // Capability, not KYC track: a provider is an "agency" / "designer"
        // by the category types it serves — a profile serving both counts in
        // both; one with no categories yet falls back to its KYC track.
        $approvedServing = fn (CategoryType $type): int => AgentProfile::query()
            ->approved()
            ->serving($type)
            ->count();

        return [
            'users_online' => $usersOnline,
            'agents_online' => $agentsOnline,
            'agencies_total' => $approvedServing(CategoryType::Agent),
            'designers_total' => $approvedServing(CategoryType::Designer),
            'orders_today' => Order::whereDate('created_at', today())->count(),
            'active_orders' => Order::whereIn('status', [
                OrderStatus::New->value,
                OrderStatus::OffersSent->value,
            ])->count(),
        ];
    }

    /**
     * Centrifugo down → null (the client hides the number rather than
     * showing a misleading 0).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function presenceCounts(): array
    {
        try {
            return [
                $this->centrifugo->presenceUsers((string) config('realtime.channels.pulse')),
                $this->centrifugo->presenceUsers((string) config('realtime.channels.agents')),
            ];
        } catch (Throwable $e) {
            Log::warning('realtime.presence_unavailable', ['error' => $e->getMessage()]);

            return [null, null];
        }
    }

    /** @return array{0: int, 1: int} */
    private function legacyOnlineCounts(): array
    {
        $onlineUserIds = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('last_used_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES))
            ->pluck('tokenable_id')
            ->unique();

        $agentsOnline = $onlineUserIds->isEmpty() ? 0 : AgentProfile::query()
            ->whereIn('user_id', $onlineUserIds->all())
            ->approved()
            ->serving(CategoryType::Agent)
            ->distinct('user_id')
            ->count('user_id');

        return [$onlineUserIds->count(), $agentsOnline];
    }
}
