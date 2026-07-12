<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Resources\AdvantageResource;
use App\Models\Advantage;
use Illuminate\Http\JsonResponse;

class AdvantageController extends ApiController
{
    /**
     * Active advantages catalog — providers pick from this list in their
     * profile editor.
     */
    public function index(): JsonResponse
    {
        $advantages = Advantage::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success(AdvantageResource::collection($advantages));
    }
}
