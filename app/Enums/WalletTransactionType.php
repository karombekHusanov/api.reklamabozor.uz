<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Topup = 'topup';
    case Pass = 'pass';
    case ResponseFee = 'response_fee';
    case Adjustment = 'adjustment';
}
