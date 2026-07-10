<?php

namespace App\Services\Admin;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates marketplace analytics for the admin dashboard.
 *
 * All date bucketing uses DATE(created_at), which is portable across
 * PostgreSQL (prod) and SQLite (tests). Time-difference metrics are
 * computed in PHP for the same reason.
 *
 * GMV = sum of accepted offer prices. Acceptance time is approximated by
 * the offer's updated_at (acceptance is the only offer mutation today).
 * Revenue (commission/subscription) will slot into the same payload later.
 */
class AnalyticsService
{
    /** Order statuses that count as an open (non-terminal) deal. */
    private const OPEN_STATUSES = [
        OrderStatus::New,
        OrderStatus::OffersSent,
        OrderStatus::ClientSelected,
        OrderStatus::InProgress,
        OrderStatus::WorkSubmitted,
    ];

    /**
     * Full dashboard payload for the given period.
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $days): array
    {
        $now = CarbonImmutable::now();
        $from = $now->subDays($days)->startOfDay();
        $previousFrom = $from->subDays($days);

        return [
            'period' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => $now->toDateString(),
            ],
            'ops' => $this->ops(),
            'kpis' => $this->kpis($from, $previousFrom),
            'trends' => $this->trends($from, $now),
            'funnel' => $this->funnel($from),
            'liquidity' => $this->liquidity($from),
            'categories' => $this->categories($from),
            'agents' => $this->topAgents($from),
        ];
    }

    /**
     * Latest marketplace events, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activity(int $limit = 20): array
    {
        $events = collect();

        Order::query()->with('client')->latest()->limit($limit)->get()
            ->each(function (Order $order) use ($events): void {
                $events->push([
                    'type' => 'order_created',
                    'at' => $order->created_at?->toIso8601String(),
                    'title' => $order->title,
                    'actor' => $order->client?->first_name ?? 'Client',
                    'order_id' => $order->id,
                ]);
            });

        Offer::query()->with(['agent.agentProfile', 'order'])->latest()->limit($limit)->get()
            ->each(function (Offer $offer) use ($events): void {
                $events->push([
                    'type' => $offer->status === OfferStatus::Accepted ? 'offer_accepted' : 'offer_sent',
                    'at' => $offer->created_at?->toIso8601String(),
                    'title' => $offer->order?->title,
                    'actor' => $offer->agent?->agentProfile?->company_name
                        ?? $offer->agent?->first_name
                        ?? 'Agent',
                    'order_id' => $offer->order_id,
                    'price' => (float) $offer->price,
                ]);
            });

        Order::query()->whereNotNull('completed_at')->latest('completed_at')->limit($limit)->get()
            ->each(function (Order $order) use ($events): void {
                $events->push([
                    'type' => 'order_completed',
                    'at' => $order->completed_at?->toIso8601String(),
                    'title' => $order->title,
                    'actor' => null,
                    'order_id' => $order->id,
                    'auto' => $order->auto_completed,
                ]);
            });

        Review::query()->with('client')->latest()->limit($limit)->get()
            ->each(function (Review $review) use ($events): void {
                $events->push([
                    'type' => 'review_submitted',
                    'at' => $review->created_at?->toIso8601String(),
                    'title' => null,
                    'actor' => $review->client?->first_name ?? 'Client',
                    'order_id' => $review->order_id,
                    'rating' => $review->rating,
                ]);
            });

        AgentProfile::query()->with('user')->latest()->limit($limit)->get()
            ->each(function (AgentProfile $profile) use ($events): void {
                $events->push([
                    'type' => 'agent_applied',
                    'at' => $profile->created_at?->toIso8601String(),
                    'title' => $profile->company_name,
                    'actor' => $profile->user?->first_name,
                    'agent_profile_id' => $profile->id,
                ]);
            });

        return $events
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Operational queue counts — what needs admin attention right now.
     *
     * @return array<string, int>
     */
    private function ops(): array
    {
        return [
            'kyc_pending' => AgentProfile::query()->pending()->count(),
            'reviews_pending' => Review::query()->where('status', ReviewStatus::Pending)->count(),
            'stuck_orders' => Order::query()->stuck()->count(),
            'dead_orders' => Order::query()->withoutOffers()->count(),
        ];
    }

    /**
     * Headline metrics with current vs previous period deltas.
     *
     * @return array<string, mixed>
     */
    private function kpis(CarbonImmutable $from, CarbonImmutable $previousFrom): array
    {
        $newUsers = $this->periodPair(
            User::query()->where('role', '!=', Role::Admin),
            'created_at',
            $from,
            $previousFrom,
        );

        $newOrders = $this->periodPair(Order::query(), 'created_at', $from, $previousFrom);

        $gmv = $this->periodPair(
            Offer::query()->where('status', OfferStatus::Accepted),
            'updated_at',
            $from,
            $previousFrom,
            'price',
        );

        $completedTerminal = Order::query()->where('status', OrderStatus::Completed)->count();
        $cancelledTerminal = Order::query()->where('status', OrderStatus::Cancelled)->count();
        $terminal = $completedTerminal + $cancelledTerminal;

        $approvedReviews = Review::query()->where('status', ReviewStatus::Approved);

        return [
            'users' => [
                'total' => User::query()->where('role', '!=', Role::Admin)->count(),
                'new' => $newUsers,
            ],
            'agents' => [
                'approved' => AgentProfile::query()->approved()->count(),
                'pending' => AgentProfile::query()->pending()->count(),
            ],
            'orders' => [
                'total' => Order::query()->count(),
                'open' => Order::query()->whereIn('status', self::OPEN_STATUSES)->count(),
                'new' => $newOrders,
            ],
            'gmv' => [
                'total' => (float) Offer::query()->where('status', OfferStatus::Accepted)->sum('price'),
                'period' => $gmv,
            ],
            'completion_rate' => $terminal > 0
                ? round($completedTerminal / $terminal * 100, 1)
                : null,
            'rating' => [
                'average' => round((float) $approvedReviews->clone()->avg('rating'), 2) ?: null,
                'count' => $approvedReviews->clone()->count(),
            ],
        ];
    }

    /**
     * Daily time series aligned to a shared label axis.
     *
     * @return array<string, array<int, string|int|float>>
     */
    private function trends(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $labels = collect();
        for ($day = $from; $day->lte($now); $day = $day->addDay()) {
            $labels->push($day->toDateString());
        }

        return [
            'labels' => $labels->values()->all(),
            'orders' => $this->dailyCounts(Order::query(), 'created_at', $from, $labels),
            'offers' => $this->dailyCounts(Offer::query(), 'created_at', $from, $labels),
            'registrations' => $this->dailyCounts(
                User::query()->where('role', '!=', Role::Admin),
                'created_at',
                $from,
                $labels,
            ),
            'gmv' => $this->dailyCounts(
                Offer::query()->where('status', OfferStatus::Accepted),
                'updated_at',
                $from,
                $labels,
                'price',
            ),
        ];
    }

    /**
     * Current status distribution of orders created in the period.
     *
     * @return array<string, mixed>
     */
    private function funnel(CarbonImmutable $from): array
    {
        $counts = Order::query()
            ->where('created_at', '>=', $from)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];
        foreach (OrderStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return [
            'statuses' => $byStatus,
            'total' => array_sum($byStatus),
            'auto_completed' => Order::query()
                ->where('created_at', '>=', $from)
                ->where('auto_completed', true)
                ->count(),
        ];
    }

    /**
     * Marketplace liquidity health: how fast and how often orders get bids.
     *
     * @return array<string, mixed>
     */
    private function liquidity(CarbonImmutable $from): array
    {
        $periodOrders = Order::query()->where('created_at', '>=', $from);

        $orderCount = $periodOrders->clone()->count();
        $withOffers = $periodOrders->clone()->has('offers')->count();

        // Time from order creation to its first offer, averaged in PHP —
        // portable across PostgreSQL and SQLite.
        $firstOffers = Offer::query()
            ->select('order_id', DB::raw('MIN(created_at) as first_offer_at'))
            ->whereHas('order', fn ($q) => $q->where('created_at', '>=', $from))
            ->groupBy('order_id')
            ->pluck('first_offer_at', 'order_id');

        $orderCreatedAt = Order::query()
            ->whereIn('id', $firstOffers->keys())
            ->pluck('created_at', 'id');

        $hours = $firstOffers
            ->map(function (string $firstOfferAt, int $orderId) use ($orderCreatedAt): ?float {
                $createdAt = $orderCreatedAt[$orderId] ?? null;

                return $createdAt
                    ? round($createdAt->diffInMinutes(CarbonImmutable::parse($firstOfferAt)) / 60, 1)
                    : null;
            })
            ->filter(fn (?float $value) => $value !== null);

        $offerTotal = Offer::query()
            ->whereHas('order', fn ($q) => $q->where('created_at', '>=', $from))
            ->count();

        return [
            'avg_offers_per_order' => $orderCount > 0 ? round($offerTotal / $orderCount, 1) : null,
            'orders_with_offers_pct' => $orderCount > 0 ? round($withOffers / $orderCount * 100, 1) : null,
            'avg_time_to_first_offer_hours' => $hours->isNotEmpty() ? round($hours->avg(), 1) : null,
            'avg_views_per_order' => $orderCount > 0
                ? round(
                    DB::table('order_views')
                        ->whereIn('order_id', Order::query()->where('created_at', '>=', $from)->select('id'))
                        ->count() / $orderCount,
                    1,
                )
                : null,
            'dead_orders' => Order::query()->withoutOffers()
                ->with('category')
                ->withCount('views')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'title' => $order->title,
                    'category' => $order->category?->name_uz,
                    'created_at' => $order->created_at?->toIso8601String(),
                    'views' => $order->views_count,
                ])
                ->all(),
        ];
    }

    /**
     * Order volume and GMV per category for the period, busiest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categories(CarbonImmutable $from): array
    {
        return Order::query()
            ->where('orders.created_at', '>=', $from)
            ->join('categories', 'categories.id', '=', 'orders.category_id')
            ->leftJoin('offers', function ($join): void {
                $join->on('offers.order_id', '=', 'orders.id')
                    ->where('offers.status', OfferStatus::Accepted->value);
            })
            ->select(
                'categories.id',
                'categories.name_uz',
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('COALESCE(SUM(offers.price), 0) as gmv'),
            )
            ->groupBy('categories.id', 'categories.name_uz')
            ->orderByDesc('orders_count')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name_uz,
                'orders' => (int) $row->orders_count,
                'gmv' => (float) $row->gmv,
            ])
            ->all();
    }

    /**
     * Top agents by won deals in the period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topAgents(CarbonImmutable $from): array
    {
        $rows = Offer::query()
            ->where('offers.created_at', '>=', $from)
            ->select(
                'agent_id',
                DB::raw('COUNT(*) as offers_count'),
                DB::raw(sprintf(
                    "SUM(CASE WHEN offers.status = '%s' THEN 1 ELSE 0 END) as won_count",
                    OfferStatus::Accepted->value,
                )),
                DB::raw(sprintf(
                    "SUM(CASE WHEN offers.status = '%s' THEN offers.price ELSE 0 END) as gmv",
                    OfferStatus::Accepted->value,
                )),
            )
            ->groupBy('agent_id')
            ->orderByDesc('won_count')
            ->orderByDesc('offers_count')
            ->limit(5)
            ->get();

        $profiles = AgentProfile::query()
            ->whereIn('user_id', $rows->pluck('agent_id'))
            ->withAvg('approvedReviews', 'rating')
            ->get()
            ->keyBy('user_id');

        $users = User::query()->whereIn('id', $rows->pluck('agent_id'))->get()->keyBy('id');

        return $rows
            ->map(function ($row) use ($profiles, $users): array {
                $profile = $profiles[$row->agent_id] ?? null;
                $offers = (int) $row->offers_count;
                $won = (int) $row->won_count;

                return [
                    'agent_id' => (int) $row->agent_id,
                    'name' => $profile?->company_name
                        ?? $users[$row->agent_id]?->first_name
                        ?? 'Agent',
                    'offers' => $offers,
                    'won' => $won,
                    'win_rate' => $offers > 0 ? round($won / $offers * 100, 1) : 0.0,
                    'gmv' => (float) $row->gmv,
                    'rating' => $profile?->approved_reviews_avg_rating !== null
                        ? round((float) $profile->approved_reviews_avg_rating, 2)
                        : null,
                ];
            })
            ->all();
    }

    /**
     * Count (or sum) for the current period vs the one before it.
     *
     * @return array{current: float|int, previous: float|int, change_pct: float|null}
     */
    private function periodPair(
        $query,
        string $column,
        CarbonImmutable $from,
        CarbonImmutable $previousFrom,
        ?string $sumColumn = null,
    ): array {
        $current = $sumColumn
            ? (float) $query->clone()->where($column, '>=', $from)->sum($sumColumn)
            : $query->clone()->where($column, '>=', $from)->count();

        $previous = $sumColumn
            ? (float) $query->clone()
                ->where($column, '>=', $previousFrom)
                ->where($column, '<', $from)
                ->sum($sumColumn)
            : $query->clone()
                ->where($column, '>=', $previousFrom)
                ->where($column, '<', $from)
                ->count();

        return [
            'current' => $current,
            'previous' => $previous,
            'change_pct' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];
    }

    /**
     * Daily count/sum series aligned to the label axis (zero-filled).
     *
     * @param  Collection<int, string>  $labels
     * @return array<int, int|float>
     */
    private function dailyCounts(
        $query,
        string $column,
        CarbonImmutable $from,
        Collection $labels,
        ?string $sumColumn = null,
    ): array {
        $aggregate = $sumColumn ? "SUM({$sumColumn})" : 'COUNT(*)';

        $rows = $query->clone()
            ->where($column, '>=', $from)
            ->select(DB::raw("DATE({$column}) as day"), DB::raw("{$aggregate} as total"))
            ->groupBy('day')
            ->pluck('total', 'day');

        return $labels
            ->map(fn (string $day) => $sumColumn
                ? (float) ($rows[$day] ?? 0)
                : (int) ($rows[$day] ?? 0))
            ->all();
    }
}
