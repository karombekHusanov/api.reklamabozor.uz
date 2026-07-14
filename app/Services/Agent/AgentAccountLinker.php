<?php

namespace App\Services\Agent;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Links a manager-created agent account to the real person once they show up
 * in the mini app. Admin-created agents have a phone but no telegram_id; when
 * a Telegram user shares that same phone, their account adopts the placeholder's
 * approved agent profile and the placeholder is removed.
 */
class AgentAccountLinker
{
    /**
     * Call after $user->phone has been captured/saved.
     */
    public function linkByPhone(User $user): void
    {
        if ($user->phone === null) {
            return;
        }

        // The Telegram user may already be an agent — nothing to adopt.
        if ($user->agentProfile()->exists()) {
            return;
        }

        $placeholder = User::query()
            ->where('phone', $user->phone)
            ->whereNull('telegram_id')
            ->where('role', Role::Agent)
            ->whereKeyNot($user->id)
            ->whereHas('agentProfile')
            ->first();

        if ($placeholder === null) {
            return;
        }

        DB::transaction(function () use ($user, $placeholder): void {
            // Hand the approved profile to the real account. The mini app may
            // already hold a token for $user, so the placeholder is the one
            // that must go.
            $placeholder->agentProfile()->update(['user_id' => $user->id]);

            $user->grantRole(Role::Agent);
            $user->role = Role::Agent;
            $user->role_selected_at ??= now();
            $user->save();

            $placeholder->delete();
        });
    }
}
