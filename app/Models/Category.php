<?php

namespace App\Models;

use App\Enums\AgentProfileStatus;
use App\Enums\CategoryType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name_uz',
        'name_ru',
        'type',
        'is_active',
        'is_other',
        'sort_order',
    ];

    /**
     * Default MXIK (fiscal classifier) code for pricelist rows in this
     * category. Stored in `category_mxik_defaults` so the classifier catalogue
     * owns the mapping.
     *
     * @return BelongsToMany<MxikCode, $this>
     */
    public function defaultMxikCode(): BelongsToMany
    {
        return $this->belongsToMany(MxikCode::class, 'category_mxik_defaults')
            ->withTimestamps();
    }

    public function agentProfiles(): BelongsToMany
    {
        return $this->belongsToMany(AgentProfile::class, 'agent_categories')
            ->withPivot('is_custom');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * True when new orders in this category should be offered to every
     * approved provider (not only those who listed the category): the
     * catch-all "Boshqa/Other" row, or a normal category with nobody
     * approved to serve it yet.
     */
    public function shouldBroadcastToAllProviders(): bool
    {
        if ($this->is_other) {
            return true;
        }

        return ! $this->hasApprovedProviders();
    }

    /**
     * Whether at least one approved provider profile lists this category.
     */
    public function hasApprovedProviders(): bool
    {
        return $this->agentProfiles()
            ->where('status', AgentProfileStatus::Approved)
            ->exists();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAgents(Builder $query): Builder
    {
        return $query->where('type', CategoryType::Agent);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDesigners(Builder $query): Builder
    {
        return $query->where('type', CategoryType::Designer);
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
     * Catch-all "Other / Boshqa" categories that always broadcast.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOther(Builder $query): Builder
    {
        return $query->where('is_other', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'is_active' => 'boolean',
            'is_other' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
