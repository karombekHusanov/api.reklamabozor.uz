<?php

namespace App\Models;

use App\Enums\AdPlacementKind;
use App\Enums\AdPlacementStatus;
use App\Enums\AgentProfileStatus;
use App\Enums\CategoryType;
use Database\Factories\AdPlacementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One advertising placement (LED screen, billboard) on the public map.
 *
 * Imported rows start `unclaimed` (owner known by name only); an approved agency
 * claiming the screen makes it `active` and routes orders straight to them.
 * The rate card lives in {@see AdPlacementRate}; without one the screen is
 * "price on request" and the client's order is quoted by agencies.
 */
class AdPlacement extends Model
{
    /** @use HasFactory<AdPlacementFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'status',
        'owner_agent_profile_id',
        'owner_name_raw',
        'owner_kind',
        'external_ref',
        'owner_phones',
        'owner_website',
        'owner_email',
        'import_key',
        'region_id',
        'address',
        'lat',
        'lng',
        'group_key',
        'photo_file_ids',
        'specs',
        'slot_seconds',
        'shows_per_hour',
        'audience_tags',
        'source',
        'imported_at',
        'claimed_at',
        'verified_at',
    ];

    /** Eager loads for the public list / detail. */
    public const PUBLIC_RELATIONS = [
        'region',
        'owner:id,user_id,company_name,status',
        'activeRates',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'owner_agent_profile_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(AdPlacementRate::class);
    }

    public function activeRates(): HasMany
    {
        return $this->rates()->where('is_active', true)->orderBy('price_tiyin');
    }

    public function orderPlacements(): HasMany
    {
        return $this->hasMany(OrderPlacement::class);
    }

    /** Screens a client may see on the map and order. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, AdPlacementStatus::bookable()));
    }

    /** Inside a "south,west,north,east" bounding box. */
    public function scopeInBbox(Builder $query, float $south, float $west, float $north, float $east): Builder
    {
        return $query->whereBetween('lat', [$south, $north])->whereBetween('lng', [$west, $east]);
    }

    public function isBookable(): bool
    {
        return $this->status->isBookable();
    }

    /** Owner is an approved agency on the platform (orders can be directed to them). */
    public function hasPlatformOwner(): bool
    {
        if ($this->owner_agent_profile_id === null) {
            return false;
        }

        $owner = $this->relationLoaded('owner') ? $this->owner : $this->owner()->first();

        return $owner?->status === AgentProfileStatus::Approved;
    }

    /** Cheapest active day rate, or null when the owner has not priced the screen. */
    public function minDayPriceTiyin(): ?int
    {
        $rates = $this->relationLoaded('activeRates') ? $this->activeRates : $this->activeRates()->get();
        $day = $rates->where('unit', 'day')->min('price_tiyin');

        return $day === null ? null : (int) $day;
    }

    public function hasPrice(): bool
    {
        $rates = $this->relationLoaded('activeRates') ? $this->activeRates : $this->activeRates()->get();

        return $rates->isNotEmpty();
    }

    /**
     * The category a placement order lands in ("LED ekran" under "Tashqi
     * reklama"). Read-only by default (null when nobody created it yet); with
     * `$createIfMissing` the child — and the root, on a bare database — are
     * created, which `placements:import`, CategorySeeder and order creation do.
     */
    public static function defaultCategory(bool $createIfMissing = false): ?Category
    {
        $childName = (string) config('placements.default_category_name_uz', 'LED ekran');

        $category = Category::query()
            ->where('type', CategoryType::Agent)
            ->where('name_uz', $childName)
            ->where('is_active', true)
            ->orderBy('depth', 'desc')
            ->first();

        if ($category !== null || ! $createIfMissing) {
            return $category;
        }

        $parentName = (string) config('placements.default_category_parent_name_uz', 'Tashqi reklama');
        $parent = Category::query()
            ->whereNull('parent_id')
            ->where('type', CategoryType::Agent)
            ->where('name_uz', $parentName)
            ->first();

        $parent ??= Category::query()->create([
            'name_uz' => $parentName,
            'name_ru' => 'Наружная реклама',
            'type' => CategoryType::Agent,
            'is_active' => true,
            'is_other' => false,
            'sort_order' => 0,
        ]);

        return Category::query()->create([
            'parent_id' => $parent->id,
            'name_uz' => $childName,
            'name_ru' => 'LED-экран',
            'is_active' => true,
            'is_other' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdPlacementKind::class,
            'status' => AdPlacementStatus::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'photo_file_ids' => 'array',
            'specs' => 'array',
            'audience_tags' => 'array',
            'owner_phones' => 'array',
            'imported_at' => 'datetime',
            'claimed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
