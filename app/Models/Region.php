<?php

namespace App\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'code',
        'name_uz',
        'name_ru',
        'is_active',
        'sort_order',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Orders that selected this row as their top-level region.
     */
    public function ordersAsRegion(): HasMany
    {
        return $this->hasMany(Order::class, 'region_id');
    }

    /**
     * Orders that selected this row as their district.
     */
    public function ordersAsDistrict(): HasMany
    {
        return $this->hasMany(Order::class, 'district_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Slug from Uzbek Latin name (oʻ/gʻ → o/g). Used for server-generated codes.
     */
    public static function codeFromName(string $nameUz): string
    {
        $normalized = mb_strtolower(trim($nameUz));
        $normalized = str_replace(
            ['oʻ', 'gʻ', 'oʼ', 'gʼ', 'o\'', 'g\'', 'ʻ', 'ʼ', "'"],
            ['o', 'g', 'o', 'g', 'o', 'g', '', '', ''],
            $normalized,
        );

        $slug = Str::slug($normalized, '-');

        return mb_substr($slug !== '' ? $slug : 'region', 0, 60);
    }

    /**
     * Unique code derived from name_uz, optionally excluding an existing row.
     */
    public static function uniqueCodeFromName(string $nameUz, ?int $exceptId = null): string
    {
        $base = self::codeFromName($nameUz);
        $code = $base;
        $suffix = 2;

        while (
            self::query()
                ->where('code', $code)
                ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
                ->exists()
        ) {
            $code = mb_substr($base, 0, 56).'-'.$suffix;
            $suffix++;
        }

        return $code;
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
