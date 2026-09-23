<?php

namespace App\Services\Pass;

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only ledger. The balance is never stored — it is SUM(amount_tiyin)
 * (GOTCHA #10 principle). Only consulted when passes.wallet_enabled is on,
 * except for admin adjustments which always work.
 */
class WalletService
{
    public function balanceTiyin(User $user): int
    {
        return (int) WalletTransaction::query()->where('user_id', $user->id)->sum('amount_tiyin');
    }

    /**
     * Positive amount added to the balance. A repeated $reference is a no-op
     * that returns the original row (idempotent top-ups).
     */
    public function credit(User $user, WalletTransactionType $type, int $amountTiyin, ?string $reference = null, ?string $note = null, ?User $by = null): WalletTransaction
    {
        if ($amountTiyin <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        return $this->write($user, $type, $amountTiyin, $reference, $note, $by);
    }

    /**
     * Debit that can never take the balance below zero (422 otherwise).
     * Safe inside an outer transaction: the user row lock serialises all of a
     * user's wallet writes.
     */
    public function debit(User $user, WalletTransactionType $type, int $amountTiyin, ?string $reference = null, ?string $note = null, ?User $by = null): WalletTransaction
    {
        if ($amountTiyin <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        return $this->write($user, $type, -$amountTiyin, $reference, $note, $by);
    }

    /** Signed manual correction; a negative one may not overdraw the wallet. */
    public function adjust(User $user, int $signedTiyin, string $reason, User $by): WalletTransaction
    {
        if ($signedTiyin === 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must not be zero.']]);
        }

        return $this->write($user, WalletTransactionType::Adjustment, $signedTiyin, null, $reason, $by);
    }

    private function write(User $user, WalletTransactionType $type, int $signedTiyin, ?string $reference, ?string $note, ?User $by): WalletTransaction
    {
        return DB::transaction(function () use ($user, $type, $signedTiyin, $reference, $note, $by): WalletTransaction {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($reference !== null) {
                $existing = WalletTransaction::query()->where('reference', $reference)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($signedTiyin < 0 && $this->balanceTiyin($user) + $signedTiyin < 0) {
                throw ValidationException::withMessages([
                    'balance' => ['Insufficient wallet balance.'],
                ]);
            }

            return WalletTransaction::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'amount_tiyin' => $signedTiyin,
                'reference' => $reference,
                'note' => $note,
                'created_by' => $by?->id,
            ]);
        });
    }
}
