<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single pricelist line on an offer (name, quantity, unit price). The parent
 * offer's `price` caches the sum of {@see lineTotal()} across its items.
 */
class OfferItem extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'offer_id',
        'name',
        'unit',
        'quantity',
        'unit_price',
        'mxik_code',
        'vat_rate',
        'sort_order',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * Line total = quantity * unit_price, at 2-decimal precision.
     */
    public function lineTotal(): string
    {
        return bcmul((string) $this->quantity, (string) $this->unit_price, 2);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
