<?php

namespace App\Http\Controllers\Api\V1\Placement;

use App\Http\Controllers\ApiController;
use App\Http\Resources\PlacementDetailResource;
use App\Http\Resources\PlacementResource;
use App\Models\AdPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Advertising placements (LED screens) on the public map. No auth: a screen on
 * a street is public information, and the map must work for guests too.
 */
class PublicPlacementController extends ApiController
{
    /**
     * Lightweight list for the map layer. Filters: `bbox` ("south,west,north,east"),
     * `region_id` (district), `with_price=1`, `q` (address/owner), `limit`
     * (default 500, max 1000 — the whole city fits in one response).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bbox' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'with_price' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = AdPlacement::query()
            ->bookable()
            ->with(AdPlacement::PUBLIC_RELATIONS)
            ->when(isset($validated['region_id']), fn ($q) => $q->where('region_id', (int) $validated['region_id']))
            ->when(
                ! empty($validated['with_price']),
                fn ($q) => $q->whereHas('rates', fn ($r) => $r->where('is_active', true)),
            );

        if (! empty($validated['bbox'])) {
            [$south, $west, $north, $east] = array_map('floatval', explode(',', $validated['bbox']));
            $query->inBbox(min($south, $north), min($west, $east), max($south, $north), max($west, $east));
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw('LOWER(address) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(owner_name_raw) LIKE ?', [$like]);
            });
        }

        $placements = $query
            ->orderBy('region_id')
            ->orderBy('id')
            ->limit((int) ($validated['limit'] ?? 500))
            ->get();

        return $this->success(PlacementResource::collection($placements));
    }

    public function show(AdPlacement $adPlacement): JsonResponse
    {
        if (! $adPlacement->isBookable()) {
            return $this->error('Placement not found.', 404);
        }

        $adPlacement->load(AdPlacement::PUBLIC_RELATIONS);

        return $this->success(new PlacementDetailResource($adPlacement));
    }
}
