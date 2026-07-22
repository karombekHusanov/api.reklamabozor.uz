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
}
