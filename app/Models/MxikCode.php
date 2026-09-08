<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry of the MXIK (IKPU) classifier as used on fiscal receipts.
 *
 * The code, its packaging code and VAT rate travel onto every pricelist row so
 * Multicard can build (and later cancel) the OFD receipt.
 */
class MxikCode extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'package_code',
        'name_uz',
        'name_ru',
        'vat_rate',
        'unit',
        'note',
        'is_active',
        'sort_order',
    ];

    /**
     * Categories that use this code by default for new pricelist rows.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_mxik_defaults')
            ->withTimestamps();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The fiscal fields a pricelist row (and the OFD line) needs.
     *
     * @return array{mxik_code: string, package_code: string|null, vat_rate: string}
     */
    public function fiscalFields(): array
    {
        return [
            'mxik_code' => $this->code,
            'package_code' => $this->package_code,
            'vat_rate' => (string) $this->vat_rate,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
