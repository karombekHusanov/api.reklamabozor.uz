<?php

namespace App\Enums;

/**
 * Moderation status of an individual executor's request to be raised to the
 * legal-entity track (`provider_type` individual → agent).
 */
enum AgentProfileUpgradeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
