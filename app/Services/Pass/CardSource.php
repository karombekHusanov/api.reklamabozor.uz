<?php

namespace App\Services\Pass;

use App\Models\SavedCard;

/**
 * What an in-app card payment is charged to: a saved card, or a card typed in
 * now (optionally bound and saved for next time).
 */
final readonly class CardSource
{
    private function __construct(
        public ?SavedCard $savedCard,
        public ?string $cardNumber,
        public ?string $expiryYymm,
        public bool $save,
    ) {}

    public static function saved(SavedCard $card): self
    {
        return new self($card, null, null, false);
    }

    /** @param  string  $expiryYymm  Year + month, e.g. "2801" = 2028-01. */
    public static function card(string $cardNumber, string $expiryYymm, bool $save = false): self
    {
        return new self(null, $cardNumber, $expiryYymm, $save);
    }
}
