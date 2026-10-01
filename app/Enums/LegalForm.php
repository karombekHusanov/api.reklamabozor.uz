<?php

namespace App\Enums;

/**
 * Organizational-legal form an agency registers with. The profile column
 * stays a plain string (older rows hold free text); this enum is the
 * allowed set for new KYC submissions and tells which documents they need.
 */
enum LegalForm: string
{
    case YaTT = 'YaTT';
    case MChJ = 'MChJ';
    case AJ = 'AJ';

    /** MChJ / AJ are companies run by an appointed manager; YaTT is the entrepreneur themself. */
    public function isCompany(): bool
    {
        return $this !== self::YaTT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f) => $f->value, self::cases());
    }
}
