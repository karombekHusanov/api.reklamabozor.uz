<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexBannersRequest;
use App\Http\Requests\Api\V1\Admin\StoreBannerRequest;
use App\Http\Requests\Api\V1\Admin\ToggleBannerActiveRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBannerRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Services\Admin\BannerAdminService;
use Illuminate\Http\JsonResponse;

class BannerController extends ApiController
{
    public function __construct(
        private readonly BannerAdminService $bannerAdminService,
    ) {}

    public function index(IndexBannersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->bannerAdminService->list([
            'search' => $validated['search'] ?? null,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : null,
            'per_page' => $validated['per_page'] ?? 15,
            'sort' => $validated['sort'] ?? 'sort_order',
            'direction' => $validated['direction'] ?? 'asc',
        ]);

        return $this->success([
            'items' => BannerResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreBannerRequest $request): JsonResponse
    {
        $banner = $this->bannerAdminService->create($request->validated());

        return $this->success(new BannerResource($banner), 'Banner created', 201);
    }

    public function show(Banner $banner): JsonResponse
    {
        return $this->success(new BannerResource($banner->load('imageFile')));
    }

    public function update(UpdateBannerRequest $request, Banner $banner): JsonResponse
    {
        $updated = $this->bannerAdminService->update($banner, $request->validated());

        return $this->success(new BannerResource($updated), 'Banner updated');
    }

    public function toggleActive(ToggleBannerActiveRequest $request, Banner $banner): JsonResponse
    {
        $isActive = (bool) $request->validated('is_active');

        $updated = $this->bannerAdminService->setActive($banner, $isActive);

        $message = $isActive ? 'Banner activated' : 'Banner deactivated';

        return $this->success(new BannerResource($updated), $message);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $this->bannerAdminService->delete($banner);

        return $this->success(null, 'Banner deleted');
    }
}
