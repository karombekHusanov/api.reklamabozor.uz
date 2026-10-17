<?php

namespace App\Models;

use App\Enums\AgentProfileStatus;
use App\Enums\CategoryType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Service category — a node in a tree of unlimited depth.
 *
 * Tree columns: `parent_id` (edge), `path` ("/1/5/12/" — every ancestor id
 * and the node's own id, kept in sync automatically on create / reparent) and
 * `depth` (number of ancestors). A subtree is `path LIKE '{path}%'`; the
 * ancestor list is the path split. Children inherit the root's `type`
 * (agent | designer); the catch-all `is_other` rows are always roots and
 * never take children.
 *
 * Provider matching is by subtree: a profile listing "Dizayn" serves
 * "Dizayn → Logotip" orders too ({@see ancestorIdsWithSelf()},
 * {@see descendantIdsOf()}).
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** Separator used when rendering the full ancestor path as a label. */
    public const FULL_NAME_SEPARATOR = ' › ';

    /** Container key of the per-request catalogue memo ({@see catalogue()}). */
    private const CATALOGUE_KEY = 'categories.catalogue';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name_uz',
        'name_ru',
        'type',
        'image_file_id',
        'is_active',
        'is_other',
        'sort_order',
    ];

    /**
     * The illustration is part of how a category is rendered everywhere it is
     * embedded (orders, agent profiles, marketplace), so eager-load it by
     * default rather than N+1 through a dozen resources.
     *
     * @var list<string>
     */
    protected $with = ['imageFile'];

    protected static function booted(): void
    {
        // A child always carries its root's type and sits one level below its
        // parent; the path needs the id, so it is written right after insert.
        static::creating(function (self $category): void {
            if ($category->parent_id !== null) {
                $parent = self::query()->findOrFail($category->parent_id);
                $category->type = $parent->type;
                $category->depth = $parent->depth + 1;
            } else {
                $category->depth = 0;
            }
        });

        static::created(function (self $category): void {
            $category->refreshTreeColumns();
        });

        // Reparenting rewrites the path/depth/type of the node and its whole
        // subtree. Cycle and catch-all guards live in CategoryAdminService.
        static::updated(function (self $category): void {
            if ($category->wasChanged('parent_id')) {
                $category->refreshTreeColumns();
            } elseif ($category->wasChanged('type')) {
                $category->propagateTypeToDescendants();
            }
        });

        $forget = function (): void {
            app()->forgetInstance(self::CATALOGUE_KEY);
        };
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Optional illustration shown instead of the generated category icon.
     */
    public function imageFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'image_file_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('is_other')
            ->orderBy('sort_order')
            ->orderBy('name_uz');
    }

    /**
     * Default MXIK (fiscal classifier) code for pricelist rows in this
     * category. Stored in `category_mxik_defaults` so the classifier catalogue
     * owns the mapping. Children without their own default inherit the nearest
     * ancestor's ({@see effectiveMxikCode()}).
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

    // ------------------------------------------------------------------
    // Tree helpers
    // ------------------------------------------------------------------

    /**
     * Ids on the path from the root down to this node, this node included.
     *
     * @return list<int>
     */
    public function ancestorIdsWithSelf(): array
    {
        $path = $this->path ?? '/'.$this->getKey().'/';

        return array_values(array_map('intval', array_filter(explode('/', $path), 'strlen')));
    }

    /**
     * Ids of every ancestor, root first (empty for a root).
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        $ids = $this->ancestorIdsWithSelf();
        array_pop($ids);

        return $ids;
    }

    /**
     * Ancestors as models, root first.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ids = $this->ancestorIds();

        if ($ids === []) {
            return new Collection;
        }

        return self::query()->whereKey($ids)->orderBy('depth')->get();
    }

    /**
     * Every node below this one (any depth), excluding itself.
     *
     * @return Builder<self>
     */
    public function descendants(): Builder
    {
        return self::query()
            ->where('path', 'like', $this->path.'%')
            ->whereKeyNot($this->getKey());
    }

    /**
     * Ids of this node plus all its descendants.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        return self::descendantIdsOf([$this->getKey()]);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Whether $other sits anywhere below this node.
     */
    public function isAncestorOf(self $other): bool
    {
        return $other->getKey() !== $this->getKey()
            && str_starts_with((string) $other->path, (string) $this->path);
    }

    /**
     * Ids of the given categories plus every descendant of each (the subtree
     * union). Used to turn a provider's listed categories into the set of
     * order categories it serves.
     *
     * @param  iterable<int>  $ids
     * @return list<int>
     */
    public static function descendantIdsOf(iterable $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : iterator_to_array($ids))));

        if ($ids === []) {
            return [];
        }

        $catalogue = self::catalogue();

        $paths = collect($ids)
            ->map(fn (int $id) => $catalogue->get($id)?->path)
            ->filter()
            ->values()
            ->all();

        if ($paths === []) {
            return $ids;
        }

        return $catalogue
            ->filter(function (self $category) use ($paths): bool {
                foreach ($paths as $path) {
                    if (str_starts_with((string) $category->path, $path)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Categories (any depth) that no approved provider serves — neither the
     * category itself nor any of its ancestors is listed by an approved
     * profile. Orders there are broadcast to every approved provider.
     *
     * @return list<int>
     */
    public static function unservedIds(): array
    {
        $served = DB::table('agent_categories')
            ->join('agent_profiles', 'agent_profiles.id', '=', 'agent_categories.agent_profile_id')
            ->where('agent_profiles.status', AgentProfileStatus::Approved->value)
            ->distinct()
            ->pluck('agent_categories.category_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        return self::catalogue()
            ->filter(function (self $category) use ($served): bool {
                if ($category->is_other) {
                    return false;
                }

                foreach ($category->ancestorIdsWithSelf() as $id) {
                    if ($served->has($id)) {
                        return false;
                    }
                }

                return true;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Light rows of every category (tree columns + names), keyed by id and
     * memoized on the container — one query per request/test however many
     * subtree lookups follow. Dropped on any category write.
     *
     * @return Collection<int, self>
     */
    public static function catalogue(): Collection
    {
        if (app()->bound(self::CATALOGUE_KEY)) {
            return app()->make(self::CATALOGUE_KEY);
        }

        $catalogue = self::query()
            ->without('imageFile')
            ->get(['id', 'parent_id', 'path', 'depth', 'type', 'name_uz', 'name_ru', 'is_other', 'is_active', 'sort_order'])
            ->keyBy('id');

        app()->instance(self::CATALOGUE_KEY, $catalogue);

        return $catalogue;
    }

    /**
     * Rewrite path + depth of this node from its parent, then of its whole
     * subtree; also pulls the root type down when the node moved between
     * roots of different types.
     */
    public function refreshTreeColumns(): void
    {
        $parent = $this->parent_id !== null ? self::query()->find($this->parent_id) : null;

        $oldPath = $this->path;
        $oldDepth = (int) ($this->getOriginal('depth') ?? 0);
        $newPath = ($parent?->path ?? '/').$this->getKey().'/';
        $newDepth = $parent !== null ? $parent->depth + 1 : 0;
        $newType = $parent?->type ?? $this->type;

        self::query()->whereKey($this->getKey())->update([
            'path' => $newPath,
            'depth' => $newDepth,
            'type' => $newType,
        ]);

        $this->path = $newPath;
        $this->depth = $newDepth;
        $this->type = $newType;
        $this->syncOriginalAttributes(['path', 'depth', 'type']);

        // Descendants: swap the old prefix for the new one, shift depth by the
        // same delta, and inherit the (possibly new) root type.
        if ($oldPath !== null && $oldPath !== $newPath) {
            $delta = $newDepth - $oldDepth;
            $typeValue = $newType instanceof CategoryType ? $newType->value : (string) $newType;

            $descendants = self::query()
                ->without('imageFile')
                ->where('path', 'like', $oldPath.'%')
                ->whereKeyNot($this->getKey())
                ->get(['id', 'path', 'depth']);

            foreach ($descendants as $descendant) {
                self::query()->whereKey($descendant->id)->update([
                    'path' => $newPath.substr((string) $descendant->path, strlen($oldPath)),
                    'depth' => max(0, (int) $descendant->depth + $delta),
                    'type' => $typeValue,
                ]);
            }
        }

        app()->forgetInstance(self::CATALOGUE_KEY);
    }

    /** Every descendant takes this node's (root's) type. */
    public function propagateTypeToDescendants(): void
    {
        $type = $this->type instanceof CategoryType ? $this->type->value : (string) $this->type;

        $this->descendants()->update(['type' => $type]);
    }

    /**
     * Nest a flat collection into roots with a recursive `children` relation
     * set on every node (sorted: catch-all last, then sort_order, then name).
     *
     * @param  Collection<int, self>  $categories
     * @return Collection<int, self>
     */
    public static function assembleTree(Collection $categories): Collection
    {
        $byParent = $categories->groupBy(fn (self $category) => $category->parent_id ?? 0);
        $known = $categories->keyBy('id');

        $sort = fn (Collection $nodes): Collection => $nodes
            ->sortBy([
                fn (self $a, self $b) => (int) $a->is_other <=> (int) $b->is_other,
                fn (self $a, self $b) => $a->sort_order <=> $b->sort_order,
                fn (self $a, self $b) => strcmp((string) $a->name_uz, (string) $b->name_uz),
            ])
            ->values();

        $attach = function (self $node) use (&$attach, $byParent, $sort): void {
            /** @var Collection<int, self> $children */
            $children = $sort($byParent->get($node->id, new Collection));
            $children->each($attach);
            $node->setRelation('children', $children);
        };

        // Roots are the nodes whose parent is absent from the given set, so a
        // filtered list (e.g. only active) still yields a well-formed forest.
        $roots = $sort($categories->filter(
            fn (self $category) => $category->parent_id === null || ! $known->has($category->parent_id),
        ));
        $roots->each($attach);

        return $roots;
    }

    /**
     * Depth-first flatten of an assembled tree (parents before children).
     *
     * @param  Collection<int, self>  $roots
     * @return Collection<int, self>
     */
    public static function flattenTree(Collection $roots): Collection
    {
        $flat = new Collection;

        $walk = function (self $node) use (&$walk, $flat): void {
            $flat->push($node);
            foreach ($node->getRelation('children') as $child) {
                $walk($child);
            }
            // Flat rows carry no nested children.
            $node->unsetRelation('children');
        };

        $roots->each($walk);

        return $flat;
    }

    /**
     * Human-readable ancestor chain, e.g. "Dizayn › Logotip › Minimalist".
     * Resolved through a per-request index (one query per request), so it is
     * safe to call from resources rendering many categories.
     */
    public function fullName(string $locale = 'uz'): string
    {
        $column = $locale === 'ru' ? 'name_ru' : 'name_uz';
        $index = self::nameIndex();
        $names = [];

        foreach ($this->ancestorIdsWithSelf() as $id) {
            if ($id === (int) $this->getKey()) {
                $names[] = (string) $this->{$column};
            } elseif (isset($index[$id])) {
                $names[] = (string) $index[$id][$column];
            }
        }

        return implode(self::FULL_NAME_SEPARATOR, $names);
    }

    /**
     * id → [name_uz, name_ru] for every category (from {@see catalogue()}).
     *
     * @return array<int, array{name_uz: string, name_ru: string}>
     */
    public static function nameIndex(): array
    {
        return self::catalogue()
            ->map(fn (self $category) => ['name_uz' => (string) $category->name_uz, 'name_ru' => (string) $category->name_ru])
            ->all();
    }

    // ------------------------------------------------------------------
    // Fiscal + provider matching
    // ------------------------------------------------------------------

    /**
     * The active default MXIK code of this category, or of the nearest
     * ancestor that has one.
     */
    public function effectiveMxikCode(): ?MxikCode
    {
        $ids = array_reverse($this->ancestorIdsWithSelf());

        $rows = DB::table('category_mxik_defaults')
            ->join('mxik_codes', 'mxik_codes.id', '=', 'category_mxik_defaults.mxik_code_id')
            ->whereIn('category_mxik_defaults.category_id', $ids)
            ->where('mxik_codes.is_active', true)
            ->pluck('category_mxik_defaults.mxik_code_id', 'category_mxik_defaults.category_id');

        foreach ($ids as $id) {
            if ($rows->has($id)) {
                return MxikCode::query()->find($rows->get($id));
            }
        }

        return null;
    }

    /**
     * True when new orders in this category should be offered to every
     * approved provider (not only those who listed the category): the
     * catch-all "Boshqa/Other" row, or a category with nobody approved to
     * serve it — neither itself nor any ancestor — yet.
     */
    public function shouldBroadcastToAllProviders(): bool
    {
        if ($this->is_other) {
            return true;
        }

        return ! $this->hasApprovedProviders();
    }

    /**
     * Whether at least one approved provider profile lists this category or
     * one of its ancestors (a provider serving "Dizayn" serves every
     * sub-category of it).
     */
    public function hasApprovedProviders(): bool
    {
        return AgentProfile::query()
            ->where('status', AgentProfileStatus::Approved)
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $this->ancestorIdsWithSelf()))
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
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
            'depth' => 'integer',
            'parent_id' => 'integer',
        ];
    }
}
