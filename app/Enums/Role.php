<?php

namespace App\Enums;

enum Role: string
{
    case Client = 'client';
    case Agent = 'agent';
    case Admin = 'admin';
    case Seller = 'seller';

    /**
     * Roles a user may grant themselves via PATCH /me/role. Client is the
     * baseline. Providers ("ijrochi") hold the single agent role, conferred ONLY
     * through admin-approved KYC (legal-entity or individual track), never
     * self-selected. (Seller is deferred, see
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
