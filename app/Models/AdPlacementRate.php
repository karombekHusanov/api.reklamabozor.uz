<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a placement's rate card: price (tiyin) per unit (day/week/month)
 * for a given clip length. Owners publish one row per clip length (5/10/15/20 s
 * per month is the usual card), so the calculator picks the row matching the
 * client's clip and pro-rates it by days — it never interpolates seconds.
 */
class AdPlacementRate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'ad_placement_id',
        'unit',
        'slot_seconds',
        'shows_per_hour',
        'price_tiyin',
        'min_quantity',
        'is_active',
        'valid_from',
        'valid_to',
    ];

    /** Days one unit covers; a month is `placements.month_days` (30) for pro-rating. */
    public function unitDays(): int
    {
        return match ($this->unit) {
            'week' => 7,
            'month' => max(1, (int) config('placements.month_days', 30)),
            default => 1,
        };
    }

    /** Price of one day at this rate, rounded to a whole so'm (still in tiyin). */
    public function dayPriceTiyin(): int
    {
        return $this->priceForDaysTiyin(1);
    }

    /** Pro-rated price for `$days` days, rounded to a whole so'm (still in tiyin). */
    public function priceForDaysTiyin(int $days): int
    {
        return (int) round($this->price_tiyin * $days / $this->unitDays() / 100) * 100;
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class, 'ad_placement_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'price_tiyin' => 'integer',
            'slot_seconds' => 'integer',
            'shows_per_hour' => 'integer',
            'min_quantity' => 'integer',
            'is_active' => 'boolean',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
        ];
    }
}
