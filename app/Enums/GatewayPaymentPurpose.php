<?php

namespace App\Enums;

enum GatewayPaymentPurpose: string
{
    case Pass = 'pass';
    case Topup = 'topup';
}
