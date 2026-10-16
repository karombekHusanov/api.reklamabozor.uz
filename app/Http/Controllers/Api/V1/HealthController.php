<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class HealthController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->success([
            'status' => 'ok',
            'service' => config('app.name'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
