<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentProfileStatus;
use App\Enums\CategoryType;
use App\Enums\ProviderType;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicAgentResource;
use App\Models\AgentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicAgentController extends ApiController
{
    /**
     * Public list of approved agents for the marketplace / home slider,
     * ranked by profile completeness. Optional filters: `q`, `category_ids`
     * (comma-separated), `type`, `provider_type`. `?limit` caps the result
     * (default 12, max 50).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'type' => ['nullable', Rule::enum(CategoryType::class)],
            'provider_type' => ['nullable', Rule::enum(ProviderType::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'category_ids' => ['nullable', 'string', 'max:500', 'regex:/^\d+(,\d+)*$/'],
        ]);

        $limit = (int) ($validated['limit'] ?? 12);
        $limit = max(1, min($limit, 50));

        $query = AgentProfile::query()
            ->approved()
            ->when(
                isset($validated['provider_type']),
                fn ($q) => $q->where('provider_type', $validated['provider_type']),
            )
            ->when(
                isset($validated['type']),
                fn ($q) => $q->whereHas(
                    'categories',
                    fn ($categoryQuery) => $categoryQuery->where('type', $validated['type']),
                ),
            );

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $likeTerm = '%'.mb_strtolower($search).'%';

            $query->where(function ($builder) use ($likeTerm): void {
                $builder
                    ->whereRaw('LOWER(company_name) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(location_label) LIKE ?', [$likeTerm])
                    ->orWhereHas('user', function ($userQuery) use ($likeTerm): void {
                        $userQuery
                            ->whereRaw('LOWER(first_name) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$likeTerm]);
                    });
            });
        }

        $categoryIds = $this->parseCsvIds($validated['category_ids'] ?? null);
        if ($categoryIds !== []) {
            $query->whereHas(
                'categories',
                fn ($categoryQuery) => $categoryQuery->whereIn('categories.id', $categoryIds),
            );
        }

        $agents = $query
            ->with(['companyLogoFile', 'categories', 'user.avatarFile', 'user.legalEntityVerification', 'user.identityVerification', 'cachedRating'])
            ->withCount(['completedOrders', 'approvedReviews'])
            ->withAvg('approvedReviews', 'rating')
            ->get()
            ->sortByDesc(fn (AgentProfile $profile) => $profile->completionPercent())
            ->take($limit)
            ->values();

        return $this->success(PublicAgentResource::collection($agents));
    }

    /**
     * Approved agents nearest to a point, ordered by distance. Used by the
     * new-order form to suggest agencies close to the client (default 5).
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];
        $limit = (int) ($validated['limit'] ?? 5);

        $agents = AgentProfile::query()
            ->approved()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->with(['companyLogoFile', 'categories', 'user.avatarFile', 'user.legalEntityVerification', 'user.identityVerification', 'cachedRating'])
            ->withCount(['completedOrders', 'approvedReviews'])
            ->withAvg('approvedReviews', 'rating')
            ->get()
            ->each(function (AgentProfile $profile) use ($lat, $lng): void {
                $profile->distance_m = (int) round(
                    $this->haversineMeters($lat, $lng, (float) $profile->lat, (float) $profile->lng)
                );
            })
            ->sortBy('distance_m')
            ->take($limit)
            ->values();

        return $this->success(PublicAgentResource::collection($agents));
    }

    /**
     * Public detail of a single approved agent (marketplace profile page).
     */
    public function show(AgentProfile $agentProfile): JsonResponse
    {
        abort_unless($agentProfile->status === AgentProfileStatus::Approved, 404);

        $agentProfile->load([
            'companyLogoFile',
            'categories',
            'user.avatarFile',
            'user.legalEntityVerification',
            'user.identityVerification',
            'cachedRating',
            'advantages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            'portfolioItems.imageFile',
            'portfolioItems.imageFiles',
            'portfolioItems.attachmentFiles',
            'approvedReviews' => fn ($query) => $query
                ->latest()
                ->limit(10)
                ->with(['client.avatarFile']),
        ]);
        $agentProfile->loadCount(['completedOrders', 'approvedReviews']);
        $agentProfile->loadAvg('approvedReviews', 'rating');

        return $this->success(new PublicAgentResource($agentProfile));
    }

    /**
     * Great-circle distance between two lat/lng points, in metres (Haversine).
     */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000; // metres

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
