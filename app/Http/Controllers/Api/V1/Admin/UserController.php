<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexUsersRequest;
use App\Http\Requests\Api\V1\Admin\ToggleUserActiveRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\UserAdminService;
use Illuminate\Http\JsonResponse;

class UserController extends ApiController
{
    public function __construct(
        private readonly UserAdminService $userAdminService,
    ) {}

    public function index(IndexUsersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->userAdminService->list([
            'role' => $validated['role'] ?? null,
            'search' => $validated['search'] ?? null,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : null,
            'per_page' => $validated['per_page'] ?? 15,
            'sort' => $validated['sort'] ?? 'created_at',
            'direction' => $validated['direction'] ?? 'desc',
        ]);

        return $this->success([
            'items' => UserResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->userAdminService->update(
            $user,
            $request->validated(),
            $request->user(),
        );

        return $this->success(new UserResource($updated), 'User updated');
    }

    public function toggleActive(ToggleUserActiveRequest $request, User $user): JsonResponse
    {
        $isActive = (bool) $request->validated('is_active');

        $updated = $this->userAdminService->setActive(
            $user,
            $isActive,
            $request->user(),
        );

        $message = $isActive ? 'User activated' : 'User deactivated';

        return $this->success(new UserResource($updated), $message);
    }
}
