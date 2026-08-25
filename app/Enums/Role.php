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
     * Provider roles that may NOT be held together with this one.
     *
     * Since the profile redesign (PROFILE_ARCHITECTURE.md) a user owns ONE
     * profile that can serve every capacity (advertising + design), so any
     * combination of capabilities coexists — there are no conflicts. Kept as a
     * (now always empty) seam for the callers until they are cleaned up.
     *
     * @return list<self>
     */
    public function conflictingRoles(): array
    {
        return [];
    }

    /**
     * Roles a user may grant themselves via PATCH /me/role. Client is the
     * baseline; designer is an individual provider, instantly self-served.
     * Agent is a legal-entity provider conferred ONLY through KYC approval —
     * never self-selected. (Seller is deferred, see PROFILE_ARCHITECTURE.md §8;
     * left self-selectable until its flow is decided.)
     *
     * @return list<self>
     */
    public static function selfSelectable(): array
    {
        return [self::Client, self::Designer, self::Seller];
    }

    public function isSelfSelectable(): bool
    {
        return in_array($this, self::selfSelectable(), true);
    }
}
