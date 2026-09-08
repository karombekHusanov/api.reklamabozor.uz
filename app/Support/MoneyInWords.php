<?php

namespace App\Support;

/**
 * Spells a som amount in Uzbek, the way an accountant expects to read it on an
 * act or an invoice ("bir million ikki yuz ming so'm"). Uzbek numerals do not
 * inflect, so the groups simply concatenate.
 */
class MoneyInWords
{
    private const ONES = [
        1 => 'bir', 2 => 'ikki', 3 => 'uch', 4 => "to'rt", 5 => 'besh',
        6 => 'olti', 7 => 'yetti', 8 => 'sakkiz', 9 => "to'qqiz",
    ];

    private const TENS = [
        1 => "o'n", 2 => 'yigirma', 3 => "o'ttiz", 4 => 'qirq', 5 => 'ellik',
        6 => 'oltmish', 7 => 'yetmish', 8 => 'sakson', 9 => "to'qson",
    ];

    private const SCALES = [
        1_000_000_000 => 'milliard',
        1_000_000 => 'million',
        1_000 => 'ming',
    ];

    /** "1 250 000 so'm (bir million ikki yuz ellik ming so'm)" */
    public static function som(int|float|string $amount): string
    {
        $value = (int) round((float) $amount);

        return number_format($value, 0, '.', ' ')." so'm (".self::words($value)." so'm)";
    }

    public static function words(int $value): string
    {
        if ($value < 0) {
            return 'minus '.self::words(-$value);
        }

        if ($value === 0) {
            return 'nol';
        }

        $parts = [];

        foreach (self::SCALES as $scale => $name) {
            if ($value >= $scale) {
                $count = intdiv($value, $scale);
                $value %= $scale;
                // Financial documents spell the leading digit out ("bir ming",
                // not "ming") so an amount cannot be misread.
                $parts[] = self::underThousand($count).' '.$name;
            }
        }

        if ($value > 0) {
            $parts[] = self::underThousand($value);
        }

        return implode(' ', $parts);
    }

    private static function underThousand(int $value): string
    {
        $parts = [];

        if ($value >= 100) {
            $hundreds = intdiv($value, 100);
            $parts[] = self::ONES[$hundreds].' yuz';
            $value %= 100;
        }

        if ($value >= 10) {
            $parts[] = self::TENS[intdiv($value, 10)];
            $value %= 10;
        }

        if ($value > 0) {
            $parts[] = self::ONES[$value];
        }

        return implode(' ', $parts);
    }
}
