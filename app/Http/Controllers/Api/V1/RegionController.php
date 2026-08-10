<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Resources\RegionResource;
use App\Models\Region;
use Illuminate\Http\JsonResponse;

class RegionController extends ApiController
{
    /**
     * Active top-level regions with nested active districts (order wizard).
     */
    public function index(): JsonResponse
    {
        $regions = Region::query()
            ->roots()
            ->active()
            ->with(['children' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('name_uz')
            ->get();

        return $this->success(RegionResource::collection($regions));
    }
}
