<?php

namespace App\Enums;

enum GatewayPaymentStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
