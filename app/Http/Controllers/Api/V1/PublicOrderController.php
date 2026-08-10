<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicOrderDetailResource;
use App\Http\Resources\PublicOrderResource;
use App\Models\Order;
use App\Models\OrderView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicOrderController extends ApiController
{
    /**
     * Public "live orders" feed for the home carousel / list. Shows the most
     * recent real orders (with view / offer counters). Optional filters:
     * `q` (title + hashtag), `hashtag`, `category_ids` (comma-separated ids,
     * e.g. `1,3,5`), `region_id` / `district_id`, `created_from` /
     * `created_to`. `?limit` caps the result (default 10, max 50).
     */
    public function showcase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'hashtag' => ['nullable', 'string', 'max:40'],
            'category_ids' => ['nullable', 'string', 'max:500', 'regex:/^\d+(,\d+)*$/'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'district_id' => ['nullable', 'integer', 'exists:regions,id'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
        ]);

        $limit = (int) ($validated['limit'] ?? 10);
        $limit = max(1, min($limit, 50));

        $query = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->with(['category', 'region', 'district', 'hashtags', 'client.avatarFile'])
            ->withCount(['views', 'offers']);

        $hashtag = trim((string) ($validated['hashtag'] ?? ''));
        if ($hashtag !== '') {
            $slug = $this->normalizeHashtagSlug($hashtag);
            $query->whereHas('hashtags', fn ($q) => $q->where('slug', $slug)->where('is_active', true));
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $likeTerm = '%'.mb_strtolower($this->normalizeHashtagSlug($search)).'%';
            $titleTerm = '%'.mb_strtolower($search).'%';

            $query->where(function ($builder) use ($likeTerm, $titleTerm): void {
                $builder
                    ->whereRaw('LOWER(title) LIKE ?', [$titleTerm])
                    ->orWhereHas('hashtags', function ($hashtagQuery) use ($likeTerm): void {
                        $hashtagQuery
                            ->where('is_active', true)
                            ->where(function ($inner) use ($likeTerm): void {
                                $inner
                                    ->whereRaw('LOWER(slug) LIKE ?', [$likeTerm])
                                    ->orWhereRaw('LOWER(label) LIKE ?', [$likeTerm]);
                            });
                    });
            });
        }

        $categoryIds = $this->parseCsvIds($validated['category_ids'] ?? null);
        if ($categoryIds !== []) {
            $query->whereIn('category_id', $categoryIds);
        }

        if (! empty($validated['district_id'])) {
            $query->where('district_id', (int) $validated['district_id']);
        } elseif (! empty($validated['region_id'])) {
            $query->where('region_id', (int) $validated['region_id']);
        }

        if (! empty($validated['created_from'])) {
            $query->whereDate('created_at', '>=', $validated['created_from']);
        }

        if (! empty($validated['created_to'])) {
            $query->whereDate('created_at', '<=', $validated['created_to']);
        }

        $orders = $query
            ->latest()
            ->take($limit)
            ->get();

        return $this->success(PublicOrderResource::collection($orders));
    }

    /**
     * Showcase detail — full order view for authenticated users. Providers see
     * their own offer and whether they can bid. Records an OrderView when a
     * provider views someone else's order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if ($order->status === OrderStatus::Cancelled) {
            abort(404);
        }

        $user = $request->user();

        $order->loadMissing(['category', 'region', 'district', 'hashtags', 'client.avatarFile']);
        $order->loadCount(['views', 'offers']);

        $order->load([
            'offers' => fn ($query) => $query->where('agent_id', $user->id),
        ]);

        Order::hydrateAttachmentFiles($order);

        if ($user->id !== $order->client_id) {
            $isProvider = $user->providerProfiles()->exists();
            if ($isProvider) {
                OrderView::upsert(
                    [['order_id' => $order->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]],
                    ['order_id', 'user_id'],
                    ['updated_at'],
                );
            }
        }

        return $this->success(new PublicOrderDetailResource($order));
    }

    private function normalizeHashtagSlug(string $value): string
    {
        $slug = ltrim(mb_strtolower(trim($value)), '#');

        return preg_replace('/[\s_]+/u', '-', $slug) ?? $slug;
    }

    /**
     * Parse a comma-separated id list (`1,3,5`) into unique positive ints.
     *
     * @return list<int>
     */
    private function parseCsvIds(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
