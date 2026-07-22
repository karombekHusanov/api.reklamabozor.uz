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
     * Provider roles that may NOT be held together with this one (coexistence
     * matrix). Client is the universal base and conflicts with nothing.
     * `designer` is a solo provider role; `agent` and `seller` form the
     * "business" group and may coexist. So the valid provider sets are:
     * {} , {agent}, {seller}, {agent, seller}, {designer}.
     *
     * @return list<self>
     */
    public function conflictingRoles(): array
    {
        return match ($this) {
            self::Designer => [self::Agent, self::Seller],
            self::Agent, self::Seller => [self::Designer],
            default => [],
        };
    }
}
