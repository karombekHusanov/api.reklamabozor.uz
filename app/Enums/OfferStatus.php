<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    /** The agent pulled their own pending offer/interest back — distinct from
     *  Rejected (the client picking someone else), so stats and "you lost the
     *  deal" notifications don't misattribute it. */
    case Withdrawn = 'withdrawn';
}
