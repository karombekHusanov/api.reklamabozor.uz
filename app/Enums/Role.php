<?php

namespace App\Enums;

enum Role: string
{
    case Client = 'client';
    case Agent = 'agent';
    case Designer = 'designer';
    case Admin = 'admin';
    case Seller = 'seller';

    /**
     * Roles a user may grant themselves via PATCH /me/role. Client is the
     * baseline. Providers ("ijrochi") are conferred ONLY through admin-approved
     * KYC (agent / individual tracks grant the agent role), never self-selected
     * — the designer self-serve track is deprecated. (Seller is deferred, see
     * PROFILE_ARCHITECTURE.md §8; left self-selectable until its flow is decided.)
     *
     * @return list<self>
     */
    public static function selfSelectable(): array
    {
        return [self::Client, self::Seller];
    }

    public function isSelfSelectable(): bool
    {
        return in_array($this, self::selfSelectable(), true);
    }
}
