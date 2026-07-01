<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

class PublicBannerController extends ApiController
{
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
}
