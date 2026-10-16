<?php

namespace App\Enums;

use App\Models\User;

/**
 * The legal nature of a user's account, orthogonal to their marketplace role.
 * Only clients self-declare it; for executors (agent role) it follows the KYC
 * track (`provider_type` individual / agent), so their effective type is
 * derived rather than stored — see
 * {@see User::effectivePersonType()}.
 */
enum PersonType: string
{
    case Individual = 'individual';
    case LegalEntity = 'legal_entity';
}
