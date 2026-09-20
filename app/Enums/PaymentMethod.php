<?php

namespace App\Enums;

/**
 * How the client pays for an order. Both current methods are offline: the
 * platform receives the money outside any gateway (cash desk, or a bank
 * transfer a manager confirms by hand or Kapitalbank auto-reconciliation
 * matches) and a manager confirms it in the admin panel.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';

    /**
     * Kept as a real check rather than a constant `true` — a future online
     * channel (e.g. Atmos) only needs a new case here plus a `false` branch.
     */
    public function isOffline(): bool
    {
        return true;
    }

    /**
     * @return list<self>
     */
    public static function offline(): array
    {
        return self::cases();
    }
}
