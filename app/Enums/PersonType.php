<?php

namespace App\Enums;

use App\Models\User;

/**
 * The legal nature of a user's account, orthogonal to their marketplace role.
 * Only `client`/`designer` users self-declare it; `agent`/`seller` are always
 * legal entities (they provide KYC / a bank account), so their effective type
 * is derived from the role rather than stored — see {@see User::effectivePersonType()}.
 */
enum PersonType: string
{
    case Individual = 'individual';
    case LegalEntity = 'legal_entity';
}
