<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentProfileStatus;
use App\Enums\CategoryType;
use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsController extends ApiController
{
    /** A user/token is considered "online" if its token was used this recently. */
    private const ONLINE_WINDOW_MINUTES = 10;

    /**
     * Public "live pulse" stats for the home JONLI marquee. Online counts derive
     * from Sanctum token `last_used_at` (active in the last 10 minutes). Cached
     * briefly so the polling marquee stays cheap.
     */
    public function live(): JsonResponse
    {
        $stats = Cache::remember('stats:live', 15, function (): array {
            $since = now()->subMinutes(self::ONLINE_WINDOW_MINUTES);

            $onlineUserIds = DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('last_used_at', '>=', $since)
                ->pluck('tokenable_id')
                ->unique();

            // Capability, not KYC track: a provider is an "agency" / "designer"
            // by the category types it serves (1 user = 1 profile, capability
            // derived from categories) — a legal profile serving both counts in
            // both. A provider with no categories yet falls back to its KYC
            // track, so a freshly approved agency still counts (matches the
            // Agencies page and the mini app's `isDesignerProvider`).
            $serving = fn (Builder $q, CategoryType $type): Builder => $q->where(
                fn (Builder $q) => $q
                    ->whereHas('categories', fn ($c) => $c->where('type', $type->value))
                    ->orWhere(fn (Builder $q) => $q
                        ->whereDoesntHave('categories')
                        ->where('provider_type', $type->value)),
            );

            $agentsOnline = $onlineUserIds->isEmpty() ? 0 : $serving(
                AgentProfile::query()
                    ->whereIn('user_id', $onlineUserIds->all())
                    ->where('status', AgentProfileStatus::Approved),
                CategoryType::Agent,
            )->distinct('user_id')->count('user_id');

            $approvedServing = fn (CategoryType $type): int => $serving(
                AgentProfile::query()->where('status', AgentProfileStatus::Approved),
                $type,
            )->count();

            return [
                'users_online' => $onlineUserIds->count(),
                'agents_online' => $agentsOnline,
                'agencies_total' => $approvedServing(CategoryType::Agent),
                'designers_total' => $approvedServing(CategoryType::Designer),
                'orders_today' => Order::whereDate('created_at', today())->count(),
                'active_orders' => Order::whereIn('status', [
                    OrderStatus::New->value,
                    OrderStatus::OffersSent->value,
                ])->count(),
            ];
        });

        return $this->success($stats);
    }
}
