<?php

namespace App\Enums;

enum BannerType: string
{
    /** Promotes an agency — clicking opens the agent profile. */
    case Agent = 'agent';

    /** Promotes a marketplace product — clicking opens the product detail page. */
    case Product = 'product';
}
