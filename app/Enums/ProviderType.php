<?php

namespace App\Enums;

/**
 * The kind of provider a profile represents. Stored on the profile itself so
 * it is immutable and independent of the owner's currently active role — an
 * agent who switches their active role to designer must not have their agency
 * profile "flip" to designer in the marketplace.
 */
enum ProviderType: string
{
    case Agent = 'agent';
    case Designer = 'designer';
    // Individual ("jismoniy") executor — light passport+selfie KYC, Tezkor-only,
    // no contract/payout/bank. A legal/KYC track like Agent, not a marketplace
    // capability. Shares the Agent role (held capabilities are role-based).
    case Individual = 'individual';

    /** The role a provider of this type holds. */
    public function toRole(): Role
    {
        return match ($this) {
            self::Agent => Role::Agent,
            self::Designer => Role::Designer,
            // Individual executors operate under the agent role; the lighter KYC
            // track is distinguished by provider_type, not by a separate role.
            self::Individual => Role::Agent,
        };
    }
}
