<?php

namespace App\Enums;

enum BannerType: string
{
    /** Promotes an agency — clicking opens the agent profile. */
    case Agent = 'agent';

    /** Promotes a marketplace product — clicking opens the product detail page. */
    case Product = 'product';

    /** Free-form link — clicking opens an internal deep-link or an external URL. */
    case Link = 'link';

    /** Whether this banner is driven by a target entity id (vs a raw link URL). */
    public function usesTarget(): bool
    {
        return match ($this) {
            self::Agent, self::Product => true,
            self::Link => false,
        };
    }
}
