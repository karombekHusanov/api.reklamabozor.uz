<?php

namespace App\Models;

use Database\Factories\AgentPortfolioItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One "qilgan ishimiz" portfolio card. Auto-published on create; admins can
 * take an item down (hidden_at) without touching the provider's data.
 */
#[Fillable([
    'agent_profile_id',
    'image_file_id',
    'title',
    'description',
    'link_url',
    'sort_order',
    'hidden_at',
    'hidden_by',
])]
class AgentPortfolioItem extends Model
{
    /** @use HasFactory<AgentPortfolioItemFactory> */
    use HasFactory;

    /** Hard cap per provider — keeps profiles curated, not dumped. */
    public const MAX_PER_PROFILE = 12;

    /** Showcase images per portfolio block (cover = first). */
    public const MAX_IMAGES = 10;

    /** Optional PDF/docs attached to a portfolio block. */
    public const MAX_ATTACHMENTS = 5;

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function imageFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'image_file_id');
    }

    public function imageFiles(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'agent_portfolio_item_images', 'portfolio_item_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function attachmentFiles(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'agent_portfolio_item_attachments', 'portfolio_item_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    /**
     * Items still visible on the public profile (not taken down).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden_at' => 'datetime',
        ];
    }
}
