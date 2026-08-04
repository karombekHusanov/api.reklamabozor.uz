<?php

namespace App\Enums;

enum ReviewDirection: string
{
    case ClientToProvider = 'client_to_provider';
    case ProviderToClient = 'provider_to_client';
}
