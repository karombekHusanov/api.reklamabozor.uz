<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexRegionsRequest;
use App\Http\Requests\Api\V1\Admin\StoreRegionRequest;
use App\Http\Requests\Api\V1\Admin\ToggleRegionActiveRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRegionRequest;
use App\Http\Resources\AdminRegionResource;
use App\Models\Region;
use App\Services\Admin\RegionAdminService;
use Illuminate\Http\JsonResponse;

class RegionController extends ApiController
{
    public function __construct(
        private readonly RegionAdminService $regionAdminService,
    ) {}

    public function index(IndexRegionsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->regionAdminService->list([
            'parent_id' => $validated['parent_id'] ?? null,
            'roots' => array_key_exists('roots', $validated)
                ? (bool) $validated['roots']
                : null,
            'search' => $validated['search'] ?? null,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : null,
            'per_page' => $validated['per_page'] ?? 15,
            'sort' => $validated['sort'] ?? 'sort_order',
            'direction' => $validated['direction'] ?? 'asc',
        ]);

        return $this->success([
            'items' => AdminRegionResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreRegionRequest $request): JsonResponse
    {
        $region = $this->regionAdminService->create($request->validated());

        return $this->success(new AdminRegionResource($region), 'Region created', 201);
    }

    public function show(Region $region): JsonResponse
    {
        return $this->success(new AdminRegionResource($this->regionAdminService->find($region)));
    }

    public function update(UpdateRegionRequest $request, Region $region): JsonResponse
    {
        $updated = $this->regionAdminService->update($region, $request->validated());

        return $this->success(new AdminRegionResource($updated), 'Region updated');
    }

    public function toggleActive(ToggleRegionActiveRequest $request, Region $region): JsonResponse
    {
        $isActive = (bool) $request->validated('is_active');

        $updated = $this->regionAdminService->setActive($region, $isActive);

        $message = $isActive ? 'Region activated' : 'Region deactivated';

        return $this->success(new AdminRegionResource($updated), $message);
    }

    public function destroy(Region $region): JsonResponse
    {
        $this->regionAdminService->delete($region);

        return $this->success(null, 'Region deleted');
    }
}
