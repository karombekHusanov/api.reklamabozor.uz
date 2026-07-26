<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Services\Banner\BannerEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBannerController extends ApiController
{
    public function __construct(
        private readonly BannerEventService $bannerEventService,
    ) {}

    /**
     * Active banners for the mini app home slider, ordered by sort weight.
     */
    public function index(): JsonResponse
    {
        $banners = Banner::query()
            ->active()
            ->with('imageFile')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        return $this->success(BannerResource::collection($banners));
    }

    /**
     * Record an impression when a banner becomes visible in the slider.
     * Public: guests see banners too. Client dedupes per session.
     */
    public function view(Banner $banner): JsonResponse
    {
        $this->bannerEventService->recordImpression($banner);

        return $this->success(null, 'Impression recorded');
    }

    /**
     * Record a click when a banner is tapped, attributing it to the
     * authenticated user when a bearer token is present (optional).
     */
    public function click(Request $request, Banner $banner): JsonResponse
    {
        $this->bannerEventService->recordClick($banner, $request->user('sanctum')?->id);

        return $this->success(null, 'Click recorded');
    }
}
