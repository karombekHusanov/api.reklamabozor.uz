<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\GrantTenderAccessRequest;
use App\Http\Requests\Api\V1\Admin\RevokeTenderAccessRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Order\TenderAccessService;
use Illuminate\Http\JsonResponse;

class TenderAccessController extends ApiController
{
    public function __construct(private readonly TenderAccessService $access) {}

    public function grant(GrantTenderAccessRequest $request, User $user): JsonResponse
    {
        $user = $this->access->grant($user, $request->user(), $request->validated('note'));

        return $this->success(new UserResource($user), 'Tender access granted');
    }

    public function revoke(RevokeTenderAccessRequest $request, User $user): JsonResponse
    {
        $user = $this->access->revoke($user, $request->user(), $request->validated('note'));

        return $this->success(new UserResource($user), 'Tender access revoked');
    }
}
