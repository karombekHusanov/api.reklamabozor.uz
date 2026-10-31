<?php

namespace App\Enums;

/**
 * Visibility of a placement on the public map.
 *  - unclaimed: imported, owner not on the platform yet — shown, orders broadcast.
 *  - active: an approved agency owns it — shown, orders go straight to them.
 *  - hidden: admin took it off the map.
 *  - not_for_sale: a brand's own screen (Mobiuz, a bank…) — never shown.
 */
enum AdPlacementStatus: string
{
    case Unclaimed = 'unclaimed';
    case Active = 'active';
    case Hidden = 'hidden';
    case NotForSale = 'not_for_sale';

    /** Statuses a client may see and put into an order. */
    public static function bookable(): array
    {
        return [self::Unclaimed, self::Active];
    }

    public function isBookable(): bool
    {
        return in_array($this, self::bookable(), true);
    }
}
