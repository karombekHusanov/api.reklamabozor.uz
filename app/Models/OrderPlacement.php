<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A screen the client picked on the map for an order. Address and owner are
 * frozen at order time; the live placement is joined for coordinates/specs.
 * Pricing fields are filled by the Phase 1 calculator (null = quoted by agents).
 */
class OrderPlacement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'ad_placement_id',
        'address_snapshot',
        'owner_name_snapshot',
        'rate_id',
        'unit',
        'quantity',
        'slot_seconds',
        'shows_per_hour',
        'date_from',
        'date_to',
        'estimated_price_tiyin',
        'sort_order',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
            'quantity' => 'integer',
            'slot_seconds' => 'integer',
            'shows_per_hour' => 'integer',
            'estimated_price_tiyin' => 'integer',
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
        ];
    }
}
