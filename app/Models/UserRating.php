<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached Stars / Grade snapshot for a user+role (+ optional agent_profile_id).
 * Recomputed on review approve, order complete/cancel/dispute, refund.
 */
class UserRating extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role',
        'agent_profile_id',
        'stars',
        'stars_count',
        'grade',
        'listing_boost',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    /**
     * Backward-compat aliases used by PublicAgentResource.
     */
    public function getRatingAvgAttribute(): ?float
    {
        return $this->stars_count > 0 ? (float) $this->stars : null;
    }

    public function getRatingCountAttribute(): int
    {
        return $this->stars_count;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'stars' => 'decimal:2',
            'stars_count' => 'integer',
            'grade' => 'integer',
            'listing_boost' => 'integer',
        ];
    }
}
