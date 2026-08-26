<?php

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Heal users who own a provider profile but don't hold the matching role.
 *
 * Provider onboarding used to grant the role via PATCH /me/role; that was
 * removed and profile creation now grants it directly. Anyone who applied in
 * the gap has a profile but no provider role, so their (pending) provider
 * surface is hidden. Grant the role from the profile's provider_type; bidding /
 * verified status stay gated by the APPROVED profile, so this unlocks only the
 * UI, not any real power. PROFILE_ARCHITECTURE.md §4.
 */
return new class extends Migration
{
    public function up(): void
    {
        AgentProfile::query()->with('user')->chunkById(200, function ($profiles): void {
            foreach ($profiles as $profile) {
                $user = $profile->user;
                $role = $profile->provider_type->toRole();

                if ($user === null || $user->hasRole($role)) {
                    continue;
                }

                $user->grantRole($role);
                // Make it the active role only if the user is still a plain client.
                if ($user->role->value === 'client') {
                    $user->role = $role;
                }
                $user->role_selected_at ??= now();
                $user->save();
            }
        });
    }

    public function down(): void
    {
        // Irreversible data backfill — nothing to undo.
    }
};
