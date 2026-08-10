<?php

namespace App\Models;

use Database\Factories\HashtagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Shared order-hashtag catalog. Clients type free-text tags on create; the
 * service normalizes to a slug and find-or-creates rows here.
 */
#[Fillable([
    'slug',
    'label',
    'usage_count',
    'is_active',
])]
class Hashtag extends Model
{
    /** @use HasFactory<HashtagFactory> */
    use HasFactory;

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_hashtag')->withTimestamps();
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
