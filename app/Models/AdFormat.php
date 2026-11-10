<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An advertising format sold per unit across a venue network (metro): one
 * monthly price for every unit, a count per line, optionally a network-wide
 * package. Unlike {@see AdPlacement} it has no map point of its own.
 */
class AdFormat extends Model
{
    public const MEDIUM_DIGITAL = 'digital';

    public const MEDIUM_STATIC = 'static';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code', 'venue', 'medium', 'name_uz', 'name_ru', 'specs', 'total_count', 'line_counts',
        'is_package', 'min_months', 'production_included', 'daily_reach', 'rates',
        'owner_name', 'owner_phones', 'owner_website', 'is_active', 'sort_order',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isStatic(): bool
    {
        return $this->medium === self::MEDIUM_STATIC;
    }

    /**
     * Monthly price (tiyin) for a clip length; static formats ignore the clip.
     * Null when the operator does not sell that clip length.
     */
    public function monthPriceTiyin(int $slotSeconds): ?int
    {
        $wanted = $this->isStatic() ? 0 : $slotSeconds;
        foreach ($this->rates ?? [] as $rate) {
            if ((int) $rate['slot_seconds'] === $wanted) {
                return (int) $rate['price_tiyin'];
            }
        }

        return null;
    }

    /** Cheapest monthly price (tiyin) — the headline "from" price. */
    public function minMonthPriceTiyin(): ?int
    {
        $prices = array_map(fn (array $rate) => (int) $rate['price_tiyin'], $this->rates ?? []);

        return $prices === [] ? null : min($prices);
    }

    /**
     * Clip lengths (seconds) on sale; empty for static formats.
     *
     * @return list<int>
     */
    public function slotOptions(): array
    {
        $slots = array_filter(array_map(fn (array $rate) => (int) $rate['slot_seconds'], $this->rates ?? []));
        sort($slots);

        return array_values($slots);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'line_counts' => 'array',
            'rates' => 'array',
            'owner_phones' => 'array',
            'is_package' => 'boolean',
            'production_included' => 'boolean',
            'is_active' => 'boolean',
            'total_count' => 'integer',
            'min_months' => 'integer',
            'daily_reach' => 'integer',
        ];
    }
}
