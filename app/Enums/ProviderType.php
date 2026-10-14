<?php

namespace App\Enums;

/**
 * The legal/KYC track of a provider ("ijrochi") profile. Every provider holds
 * the single agent role; this tells the two legal kinds apart. Stored on the
 * profile so it is immutable. Not the marketplace capability — that is derived
 * from the profile's categories.
 */
enum ProviderType: string
{
    // Legal entity ("yuridik") — full company KYC, agency offer, bank
    // requisites; may bid on Tender.
    case Agent = 'agent';
    // Individual ("jismoniy") executor — light passport+selfie KYC, Tezkor-only,
    // no contract/payout/bank.
    case Individual = 'individual';

    /** The role a provider of this type holds. */
    public function toRole(): Role
    {
        return match ($this) {
            self::Agent, self::Individual => Role::Agent,
        };
    }
}
