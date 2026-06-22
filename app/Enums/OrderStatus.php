<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case OffersSent = 'offers_sent';
    case ClientSelected = 'client_selected';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Statuses for which an agent can still submit an offer.
     *
     * @return list<self>
     */
    public static function openForOffers(): array
    {
        return [self::New, self::OffersSent];
    }

    public function isOpenForOffers(): bool
    {
        return in_array($this, self::openForOffers(), true);
    }
}
