<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Topup = 'topup';
    case Pass = 'pass';
    case ResponseFee = 'response_fee';
    /** Tezkor pay_on_pick: the picked agent paid to take the job. */
    case TakeFee = 'take_fee';
    case Adjustment = 'adjustment';
    /** A refunded card top-up taken back out of the wallet. */
    case Refund = 'refund';
}
