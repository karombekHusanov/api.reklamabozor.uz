<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentProfileStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicAgentResource;
use App\Models\AgentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicAgentController extends ApiController
{
    /**
     * Public list of approved agents for the marketplace / home slider,
     * ranked by profile completeness. `?limit` caps the result (default 12).
     */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->integer('limit', 12);
        $limit = max(1, min($limit, 50));

        $agents = AgentProfile::query()
            ->approved()
            ->with(['companyLogoFile', 'categories'])
            ->withCount('completedOrders')
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
            ->with(['companyLogoFile', 'categories'])
            ->withCount('completedOrders')
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

        $agentProfile->load(['companyLogoFile', 'categories']);
        $agentProfile->loadCount('completedOrders');

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
}
