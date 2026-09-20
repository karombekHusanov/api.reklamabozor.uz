<?php

namespace App\Enums;

/**
 * Payment lifecycle. Cash/bank-transfer payments move through these by hand
 * (admin confirm) or via Kapitalbank auto-reconciliation, not a gateway.
 */
enum PaymentStatus: string
{
    case Draft = 'draft';         // invoice created, not yet paid
    case Progress = 'progress';   // awaiting confirmation (cash / bank transfer)
    case Success = 'success';     // paid
    case Error = 'error';         // rejected / abandoned
    case Revert = 'revert';       // refunded / reversed
    case Hold = 'hold';           // funds blocked (escrow — future phase)

    public function isPaid(): bool
    {
        return $this === self::Success;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Error, self::Revert], true);
    }

    /**
     * Whether a webhook/reconcile may move this payment to `$next`.
     *
     * Final statuses are sticky: Success may only move to Revert (gateway
     * refund); Error and Revert never move. Same-status updates are allowed
     * (idempotent retries). Non-final statuses may advance to anything.
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Success => $next === self::Revert,
            self::Error, self::Revert => false,
            default => true,
        };
    }
}
