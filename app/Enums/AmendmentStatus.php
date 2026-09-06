<?php

namespace App\Enums;

/**
 * Lifecycle of an "Additional agreement" (Qo'shimcha kelishuv) — a proposed
 * change to an active deal's pricelist and/or deadline. It takes effect only
 * once every required party (client, agent, and — when flagged — the operator)
 * approves, and any extra payment it triggers is settled.
 */
enum AmendmentStatus: string
{
    /** Proposed; awaiting the remaining party approvals. */
    case Pending = 'pending';
    /** Fully approved but held for the extra payment to clear. */
    case Approved = 'approved';
    /** Approved (and paid, if required) — changes written into the deal. */
    case Applied = 'applied';
    /** A required party declined the change. */
    case Rejected = 'rejected';
    /** The initiator withdrew the proposal before it was applied. */
    case Cancelled = 'cancelled';
    /** No decision reached within the response window. */
    case Expired = 'expired';

    /**
     * Statuses that no longer accept approvals / rejections.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Applied, self::Rejected, self::Cancelled, self::Expired];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /** Still awaiting party decisions. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
