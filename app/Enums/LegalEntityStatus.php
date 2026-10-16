<?php

namespace App\Enums;

/**
 * Moderation status of a self-declared legal entity's verification request
 * (client submitting an INN + registration document). Legal-entity
 * executors are verified through KYC and never go through this flow.
 */
enum LegalEntityStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
