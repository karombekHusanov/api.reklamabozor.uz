<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class PlatformContactController extends ApiController
{
    /** Public support contact shown in the mini app (phone, hours, email). */
    public function __invoke(): JsonResponse
    {
        return $this->success([
            'phone' => config('legal.platform.phone') ?: null,
            'work_hours' => config('legal.platform.work_hours') ?: null,
            'email' => config('legal.platform.email') ?: null,
        ]);
    }
}
