<?php

namespace App\Http\Controllers\Api\V1;

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
            ->get()
            ->sortByDesc(fn (AgentProfile $profile) => $profile->completionPercent())
            ->take($limit)
            ->values();

        return $this->success(PublicAgentResource::collection($agents));
    }
}
