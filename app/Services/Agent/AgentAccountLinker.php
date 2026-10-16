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
     * Columns that may point at a manager-created placeholder agent and are
     * re-pointed to the adopting account before the placeholder is deleted.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const USER_REFERENCES = [
        ['orders', 'target_agent_id'],
        ['orders', 'claimed_agent_id'],
        ['offers', 'agent_id'],
        ['payouts', 'agent_id'],
        ['chats', 'agent_id'],
        ['direct_chats', 'agent_id'],
        ['reviews', 'agent_id'],
        ['reviews', 'reviewer_id'],
        ['reviews', 'reviewee_id'],
        ['user_ratings', 'user_id'],
        ['wallet_transactions', 'user_id'],
    ];

    /**
     * Call after $user->phone has been captured/saved.
     */
    public function linkByPhone(User $user): void
    {
        if ($user->phone === null) {
            return;
        }

        // The Telegram user may already be an agent — nothing to adopt.
        if ($user->profile()->exists()) {
            return;
        }

        $placeholder = User::query()
            ->where('phone', $user->phone)
            ->whereNull('telegram_id')
            ->where('role', Role::Agent)
            ->whereKeyNot($user->id)
            ->whereHas('profile')
            ->first();

        if ($placeholder === null) {
            return;
        }

        DB::transaction(function () use ($user, $placeholder): void {
            // Hand the approved profile to the real account. The mini app may
            // already hold a token for $user, so the placeholder is the one
            // that must go.
            $placeholder->profile()->update(['user_id' => $user->id]);

            // Anything a manager already attached to the placeholder (a
            // directed order, a pre-planned payout, a review…) must follow it:
            // deleting the user would null or cascade those rows away.
            foreach (self::USER_REFERENCES as [$table, $column]) {
                DB::table($table)->where($column, $placeholder->id)->update([$column => $user->id]);
            }

            $user->grantRole(Role::Agent);
            $user->role = Role::Agent;
            $user->role_selected_at ??= now();
            $user->save();

            $placeholder->delete();
        });
    }
}
