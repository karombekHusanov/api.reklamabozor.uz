<?php

namespace App\Enums;

/**
 * Lifecycle of an on-demand agent withdrawal (escrow → agent card via the
 * Multicard hosted bind form + `credit` + OTP).
 *
 *   draft → card_pending → otp_required → success
 *                                       ↘ failed
 *   (cancelled from any non-final state)
 */
enum WithdrawalStatus: string
{
    case Draft = 'draft';                 // created, reserved payouts, no card yet
    case CardPending = 'card_pending';    // hosted bind form issued, awaiting card entry
    case OtpRequired = 'otp_required';    // credit created on the gateway, awaiting OTP
    case Success = 'success';             // credited to the agent's card
    case Failed = 'failed';               // gateway credit failed
    case Cancelled = 'cancelled';         // abandoned before completion

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Cancelled], true);
    }
}
