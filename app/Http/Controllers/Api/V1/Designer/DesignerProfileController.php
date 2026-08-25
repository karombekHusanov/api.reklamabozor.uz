<?php

namespace App\Http\Controllers\Api\V1\Designer;

use App\Enums\AgentProfileStatus;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Designer\StoreDesignerProfileRequest;
use App\Http\Resources\AgentProfileResource;
use App\Models\AgentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Designers are individuals, not legal entities: no KYC, no admin gate.
 * The profile is created from a minimal form and is approved instantly —
 * quality control is reactive (admin can deactivate the user or take
 * portfolio items down).
 */
class DesignerProfileController extends ApiController
{
    public function store(StoreDesignerProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if (AgentProfile::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'profile' => ['You already have a provider profile.'],
            ]);
        }

        // Creating a designer profile IS becoming a designer — grant the role
        // here (mirrors agent approval granting the agent role). No self-select
        // step, no PATCH /me/role. PROFILE_ARCHITECTURE.md §4.
        $user->grantRole(Role::Designer);
        $user->role = Role::Designer;
        $user->role_selected_at ??= now();
        $user->save();

        $validated = $request->validated();

        $profile = AgentProfile::create([
            'user_id' => $user->id,
            'provider_type' => ProviderType::Designer,
            // Optional studio name; public display falls back to the user's name.
            'company_name' => $validated['company_name'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'phone' => $user->phone,
            'status' => AgentProfileStatus::Approved,
            'approved_at' => now(),
        ]);

        $profile->categories()->sync($validated['category_ids']);

        return $this->success(
            new AgentProfileResource($profile->load(AgentProfile::PROFILE_RELATIONS)),
            'Designer profile created',
            201,
        );
    }
}
