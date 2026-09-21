<?php

namespace App\Services\Order;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Manager-granted permission to open Tender requests, per account.
 * Revoking only blocks new tenders — in-flight ones keep running.
 */
class TenderAccessService
{
    public function __construct(private readonly OrderNotifier $notifier) {}

    public function grant(User $user, User $admin, string $note): User
    {
        // Checklist item 1: the account must be a verified legal entity.
        if (! $user->isVerifiedLegalEntity()) {
            throw ValidationException::withMessages([
                'user' => ['The account must be a verified legal entity before it can get Tender access.'],
            ]);
        }

        $user->forceFill([
            'tender_access_at' => now(),
            'tender_access_by' => $admin->id,
            // Checklist item 2: the manager's interview / business-check note.
            'tender_access_note' => $note,
            'tender_access_revoked_at' => null,
        ])->save();

        Log::info('tender_access.granted', ['user_id' => $user->id, 'by' => $admin->id, 'note' => $note]);

        $this->notify($user, true);

        return $user->fresh();
    }

    public function revoke(User $user, User $admin, ?string $note = null): User
    {
        if (! $user->canCreateTender()) {
            throw ValidationException::withMessages([
                'user' => ['This account has no active Tender access.'],
            ]);
        }

        $user->forceFill(['tender_access_revoked_at' => now()])->save();

        Log::info('tender_access.revoked', ['user_id' => $user->id, 'by' => $admin->id, 'note' => $note]);

        $this->notify($user, false);

        return $user->fresh();
    }

    private function notify(User $user, bool $granted): void
    {
        try {
            $this->notifier->notifyTenderAccess($user, $granted);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
