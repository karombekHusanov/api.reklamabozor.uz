<?php

namespace App\Enums;

enum Role: string
{
    case Client = 'client';
    case Agent = 'agent';
    case Admin = 'admin';

    /**
     * Roles a user may grant themselves via PATCH /me/role. Client is the
     * baseline. Providers ("ijrochi") hold the single agent role, conferred ONLY
     * through admin-approved KYC (legal-entity or individual track), never
     * self-selected. (The `seller` role was removed on 2026-10-17 — it never
     * had a flow; PROFILE_ARCHITECTURE.md §8.)
     *
     * @return list<self>
     */
    public static function selfSelectable(): array
    {
        return [self::Client];
    }

    public function isSelfSelectable(): bool
    {
        return in_array($this, self::selfSelectable(), true);
    }
}
