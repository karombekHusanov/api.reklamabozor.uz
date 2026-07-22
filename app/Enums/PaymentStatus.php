<?php

namespace App\Enums;

/**
 * Payment lifecycle, mirroring Multicard's transaction states.
 *
 * @see https://docs.multicard.uz PaymentStatusEnum
 */
enum PaymentStatus: string
{
    case Draft = 'draft';         // invoice created, not yet paid
    case Progress = 'progress';   // charge in progress in the payment system
    case Success = 'success';     // paid
    case Error = 'error';         // charge failed
    case Revert = 'revert';       // refunded / reversed
    case Hold = 'hold';           // funds blocked (escrow — future phase)

    /**
     * Map a raw gateway status string onto our enum, defaulting to Draft for
     * unknown/intermediate values (e.g. Multicard's "billing").
     */
    public static function fromGateway(?string $status): self
    {
        return match ($status) {
            'success' => self::Success,
            'progress', 'billing' => self::Progress,
            'error' => self::Error,
            'revert' => self::Revert,
            'hold' => self::Hold,
            default => self::Draft,
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Success;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Error, self::Revert], true);
    }
}
