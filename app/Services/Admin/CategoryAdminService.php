<?php

namespace App\Services\Admin;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD over the category tree.
 *
 * Tree rules enforced here (the model keeps path/depth/type in sync):
 *  - a child inherits its root's `type`; `type` is only editable on a root
 *    and the change cascades to the whole subtree;
 *  - the catch-all `is_other` rows are roots and never take children;
 *  - a node cannot be moved under itself or one of its descendants;
 *  - deactivating a node deactivates its subtree; a node cannot be activated
 *    while its parent is inactive; a node with children cannot be deleted.
 */
class CategoryAdminService
{
    /**
     * @param  array{
     *     type?: string|null,
     *     search?: string|null,
     *     is_active?: bool|null,
     *     parent_id?: int|null,
     *     per_page?: int,
     *     sort?: string,
     *     direction?: string
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Category::query();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null) {
            $query->where('parent_id', $filters['parent_id']);
        }

        if (! empty($filters['search'])) {
            $likeTerm = '%'.mb_strtolower($filters['search']).'%';

            $query->where(function ($builder) use ($likeTerm): void {
                $builder
                    ->whereRaw('LOWER(name_uz) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(name_ru) LIKE ?', [$likeTerm]);
            });
        }

        $sort = $filters['sort'] ?? 'sort_order';
        $direction = $filters['direction'] ?? 'asc';

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * The whole catalogue (active and inactive) as nested roots with a
     * recursive `children` relation, optionally limited to one type.
     *
     * @return Collection<int, Category>
     */
    public function tree(?string $type = null): Collection
    {
        $all = Category::query()
            ->when($type !== null && $type !== '', fn ($q) => $q->where('type', $type))
            ->get();

        return Category::assembleTree($all);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        // is_other is system-managed (seed/migration only).
        unset($data['is_other']);

        $parent = $this->resolveParent($data['parent_id'] ?? null);

        $type = $parent !== null
            ? $parent->type
            : CategoryType::from((string) ($data['type'] ?? ''));

        $isActive = (bool) ($data['is_active'] ?? true);

        if ($parent !== null && $isActive && ! $parent->is_active) {
            throw ValidationException::withMessages([
                'is_active' => ['Cannot create an active category under an inactive parent.'],
            ]);
        }

        return Category::query()->create([
            'parent_id' => $parent?->id,
            'name_uz' => $data['name_uz'],
            'name_ru' => $data['name_ru'],
            'type' => $type,
            'image_file_id' => $data['image_file_id'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $isActive,
            'is_other' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        // is_other is system-managed; type locked on catch-all rows.
        unset($data['is_other']);

        if ($category->is_other) {
            unset($data['type']);
        }

        // Reparent (including "move to root" via explicit null).
        if (array_key_exists('parent_id', $data)) {
            $newParentId = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;

            if ($newParentId !== $category->parent_id) {
                $parent = $this->resolveParent($newParentId, $category);
                $data['parent_id'] = $parent?->id;

                if ($parent !== null) {
                    // Type comes from the new root; the model cascades it.
                    unset($data['type']);
                }
            } else {
                unset($data['parent_id']);
            }
        }

        // A child never carries its own type — it is the root's.
        if (! $category->isRoot() && ! array_key_exists('parent_id', $data)) {
            unset($data['type']);
        }

        if (isset($data['type'])) {
            $data['type'] = CategoryType::from($data['type']);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $this->assertActivationAllowed($category, (bool) $data['is_active'], $data['parent_id'] ?? $category->parent_id);
        }

        return DB::transaction(function () use ($category, $data): Category {
            $category->fill($data);
            $category->save();

            if (array_key_exists('is_active', $data) && $data['is_active'] !== null && ! (bool) $data['is_active']) {
                $category->descendants()->update(['is_active' => false]);
            }

            return $category->refresh();
        });
    }

    public function setActive(Category $category, bool $isActive): Category
    {
        if ($category->is_other && ! $isActive) {
            throw ValidationException::withMessages([
                'category' => ['Cannot deactivate the catch-all "Other" category.'],
            ]);
        }

        $this->assertActivationAllowed($category, $isActive, $category->parent_id);

        return DB::transaction(function () use ($category, $isActive): Category {
            $category->is_active = $isActive;
            $category->save();

            // Deactivation hides the whole subtree; activation is explicit per
            // node so an admin re-enables children deliberately.
            if (! $isActive) {
                $category->descendants()->update(['is_active' => false]);
            }

            return $category->refresh();
        });
    }

    public function delete(Category $category): void
    {
        if ($category->is_other) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete the catch-all "Other" category.'],
            ]);
        }

        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete a category that has sub-categories — move or delete them first.'],
            ]);
        }

        if ($category->orders()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete a category that has orders.'],
            ]);
        }

        if ($category->agentProfiles()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['Cannot delete a category linked to agent profiles.'],
            ]);
        }

        $category->delete();
    }

    /**
     * Validate a parent choice: must exist, must not be a catch-all row, and
     * (when moving $self) must not be $self or one of its descendants.
     */
    private function resolveParent(?int $parentId, ?Category $self = null): ?Category
    {
        if ($parentId === null) {
            return null;
        }

        if ($self !== null && $self->is_other) {
            throw ValidationException::withMessages([
                'parent_id' => ['The catch-all "Other" category must stay at the root.'],
            ]);
        }

        $parent = Category::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent category does not exist.'],
            ]);
        }

        if ($parent->is_other) {
            throw ValidationException::withMessages([
                'parent_id' => ['The catch-all "Other" category cannot have sub-categories.'],
            ]);
        }

        if ($self !== null && ($parent->id === $self->id || $self->isAncestorOf($parent))) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be moved under itself or one of its sub-categories.'],
            ]);
        }

        return $parent;
    }

    private function assertActivationAllowed(Category $category, bool $isActive, ?int $parentId): void
    {
        if (! $isActive || $parentId === null) {
            return;
        }

        $parentActive = Category::query()->whereKey($parentId)->value('is_active');

        if (! $parentActive) {
            throw ValidationException::withMessages([
                'is_active' => ['Activate the parent category first.'],
            ]);
        }
    }
}
