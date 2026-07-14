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
     * Pick a marketplace role (multirole). The first call is onboarding; later
     * calls either switch back to an already-held role or acquire a new one.
     * `role` is the active role, `roles` accumulates everything the user holds.
     * Client is the base role every user keeps, so the picked role is always
     * added on top of it — never replacing it. Real powers stay gated elsewhere
     * (agent = KYC approval, admin = never self-selectable), so acquiring a role
     * only unlocks that flow's entry point.
     */
    public function setRole(SetRoleRequest $request): JsonResponse
    {
        $user = $request->user();
        $role = Role::from($request->validated('role'));

        $isFirstSelection = $user->role_selected_at === null;

        // Client stays as the base role; the selected role becomes the active one.
        $user->grantRole(Role::Client);
        $user->grantRole($role);
        $user->role = $role;
        $user->role_selected_at ??= now();
        $user->save();

        return $this->success(
            new UserResource($user->load('avatarFile')),
            $isFirstSelection ? 'Role selected' : 'Role updated',
        );
    }
}
