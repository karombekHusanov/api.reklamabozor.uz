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
