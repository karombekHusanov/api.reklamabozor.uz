<?php

namespace App\Enums;

/**
 * How the client pays for an order.
 *
 * `multicard` covers every gateway route (in-app checkout, invoice link, QR,
 * SMS) — the money lands in the Multicard merchant account and the webhook
 * settles it. `cash` and `bank_transfer` are offline: the platform receives the
 * money outside the gateway and a manager confirms it in the admin panel.
 */
enum PaymentMethod: string
{
    case Multicard = 'multicard';
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';

    public function isOffline(): bool
    {
        return $this !== self::Multicard;
    }

    /**
     * @return list<self>
     */
    public static function offline(): array
    {
        return [self::Cash, self::BankTransfer];
    }
}
