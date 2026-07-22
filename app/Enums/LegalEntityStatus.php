<?php

namespace App\Enums;

/**
 * Moderation status of a self-declared legal entity's verification request
 * (client/designer submitting an INN + registration document). Agents/sellers
 * are legal entities by role and never go through this flow.
 */
enum LegalEntityStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
