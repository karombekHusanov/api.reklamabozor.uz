<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a placement's rate card: price (tiyin) per unit (day/week/month)
 * for a given clip length and frequency. The estimate scales linearly from
 * these when the client picks a different slot/frequency (Phase 1).
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
