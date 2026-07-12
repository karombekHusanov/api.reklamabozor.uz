<?php

namespace App\Models;

use Database\Factories\AdvantageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Admin-managed catalog entry a provider can pick as an "advantage".
 * Icons are lucide keys rendered client-side.
 */
#[Fillable([
    'name_uz',
    'name_ru',
    'hint_uz',
    'hint_ru',
    'icon',
    'is_active',
    'sort_order',
])]
class Advantage extends Model
{
    /** @use HasFactory<AdvantageFactory> */
    use HasFactory;

    public function agentProfiles(): BelongsToMany
    {
        return $this->belongsToMany(AgentProfile::class, 'agent_profile_advantage');
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
            'is_active' => 'boolean',
        ];
    }
}
