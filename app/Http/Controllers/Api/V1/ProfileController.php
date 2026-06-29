<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\SetRoleRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

class ProfileController extends ApiController
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->validated());
        $user->save();

        return $this->success(
            new UserResource($user->load('avatarFile')),
            'Profile updated',
        );
    }

    /**
     * Set the user's marketplace role during onboarding. Allowed only once —
     * afterwards the role is fixed and only an admin may change it.
     */
    public function setRole(SetRoleRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role_selected_at !== null) {
            return $this->error('Role has already been selected.', 403);
        }

        $user->role = Role::from($request->validated('role'));
        $user->role_selected_at = now();
        $user->save();

        return $this->success(
            new UserResource($user->load('avatarFile')),
            'Role selected',
        );
    }
}
