<?php

namespace App\Services\Admin;

use App\Enums\OrderRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-client-account order counts by route (Tender vs Tezkor). Managers use it
 * to spot Tender-commission leakage: accounts with tender access that keep
 * posting Tezkor orders. Counts every order, cancelled included.
 */
class OrdersByRouteReportService
{
    /**
     * @param  array{tender_access?: ?string, from?: ?string, to?: ?string, page?: int, per_page?: int}  $filters
     * @return array{totals: array{tender: int, tezkor: int}, items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function report(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $tender = OrderRoute::Tender->value;
        $tezkor = OrderRoute::Tezkor->value;

        $query = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.client_id')
            ->leftJoin('legal_entity_verifications as lev', 'lev.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.tender_access_at', 'users.tender_access_revoked_at', 'lev.company_name')
            ->selectRaw(
                'users.id as client_id, users.first_name, users.last_name, users.tender_access_at, '
                .'users.tender_access_revoked_at, lev.company_name, '
                .'SUM(CASE WHEN orders.route = ? THEN 1 ELSE 0 END) as tender, '
                .'SUM(CASE WHEN orders.route = ? THEN 1 ELSE 0 END) as tezkor',
                [$tender, $tezkor],
            );

        if (! empty($filters['from'])) {
            $query->where('orders.created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('orders.created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
        if (($filters['tender_access'] ?? null) === 'granted') {
            $query->whereNotNull('users.tender_access_at')->whereNull('users.tender_access_revoked_at');
        }

        // Totals and pagination both derive from the same grouped rows.
        $rows = $query->orderByRaw('tezkor desc, (SUM(CASE WHEN orders.route = ? THEN 1 ELSE 0 END) + SUM(CASE WHEN orders.route = ? THEN 1 ELSE 0 END)) desc, users.id asc', [$tender, $tezkor])
            ->get();

        $totals = [
            'tender' => (int) $rows->sum('tender'),
            'tezkor' => (int) $rows->sum('tezkor'),
        ];

        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values()->map(fn ($r) => [
            'client_id' => (int) $r->client_id,
            'name' => trim($r->first_name.' '.($r->last_name ?? '')),
            'company_name' => $r->company_name ?: null,
            'can_create_tender' => $r->tender_access_at !== null && $r->tender_access_revoked_at === null,
            'tender' => (int) $r->tender,
            'tezkor' => (int) $r->tezkor,
        ])->all();

        return [
            'totals' => $totals,
            'items' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $rows->count(),
            ],
        ];
    }
}
