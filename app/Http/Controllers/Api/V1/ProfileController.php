<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PersonType;
use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\SetPersonTypeRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Self-declared legal nature (individual / legal entity). Asked only of
     * client/designer users; agents & sellers derive it from their role, so the
     * stored value is ignored for them ({@see User::effectivePersonType()}).
     * A self-declared legal entity stays unverified until Phase-2 verification.
     */
    public function setPersonType(SetPersonTypeRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->person_type = PersonType::from($request->validated('person_type'));
        $user->person_type_selected_at = now();
        // Person-type is the final onboarding step (no separate role pick), so
        // stamp role_selected_at to mark onboarding complete. Everyone starts
        // as a client; providers are acquired later via their own flows.
        $user->role_selected_at ??= now();
        $user->save();

        return $this->success(
            new UserResource($user->load('avatarFile')),
            'Person type updated',
        );
    }

    /**
     * Record the user's acceptance of the current public offer (Terms of Use)
     * version. Idempotent — re-accepting simply refreshes the timestamp.
     */
    public function acceptTerms(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->acceptCurrentTerms();

        return $this->success(
            new UserResource($user->load('avatarFile')),
            'Terms accepted',
        );
    }
}
