<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\Region;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class RegionAdminService
{
    /**
     * @param  array{
     *     parent_id?: int|null,
     *     roots?: bool|null,
     *     search?: string|null,
     *     is_active?: bool|null,
     *     per_page?: int,
     *     sort?: string,
     *     direction?: string
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Region::query()
            ->with('parent')
            ->withCount(['children', 'ordersAsRegion', 'ordersAsDistrict']);

        if (! empty($filters['roots'])) {
            $query->roots();
        } elseif (array_key_exists('parent_id', $filters) && $filters['parent_id'] !== null) {
            $query->where('parent_id', $filters['parent_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $likeTerm = '%'.mb_strtolower($filters['search']).'%';

            $query->where(function ($builder) use ($likeTerm): void {
                $builder
                    ->whereRaw('LOWER(name_uz) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(name_ru) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$likeTerm]);
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
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Region
    {
        $parentId = $data['parent_id'] ?? null;
        $this->assertValidParent($parentId);

        $region = Region::query()->create([
            'parent_id' => $parentId,
            'code' => Region::uniqueCodeFromName($data['name_uz']),
            'name_uz' => $data['name_uz'],
            'name_ru' => $data['name_ru'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $region->load('parent')->loadCount(['children', 'ordersAsRegion', 'ordersAsDistrict']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Region $region, array $data): Region
    {
        // Code is server-generated and never edited by admin.
        unset($data['code']);

        // Empty admin form field arrives as null; column is NOT NULL.
        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            $data['sort_order'] = $region->sort_order ?? 0;
        }

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'];
            if ($parentId !== null && (int) $parentId === (int) $region->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A region cannot be its own parent.'],
                ]);
            }

            $normalizedParentId = $parentId === null ? null : (int) $parentId;
            $currentParentId = $region->parent_id === null ? null : (int) $region->parent_id;
            if ($normalizedParentId !== $currentParentId && $this->regionHasOrders($region)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Cannot re-parent a region that has orders.'],
                ]);
            }

            $this->assertValidParent($parentId, $region);
            $data['parent_id'] = $parentId;
        }

        $region->fill($data);
        $region->save();

        return $region->refresh()
            ->load('parent')
            ->loadCount(['children', 'ordersAsRegion', 'ordersAsDistrict']);
    }

    public function setActive(Region $region, bool $isActive): Region
    {
        $region->is_active = $isActive;
        $region->save();

        return $region->refresh()
            ->load('parent')
            ->loadCount(['children', 'ordersAsRegion', 'ordersAsDistrict']);
    }

    public function delete(Region $region): void
    {
        if ($region->children()->exists()) {
            throw ValidationException::withMessages([
                'region' => ['Cannot delete a region that has districts.'],
            ]);
        }

        if ($this->regionHasOrders($region)) {
            throw ValidationException::withMessages([
                'region' => ['Cannot delete a region that has orders.'],
            ]);
        }

        $region->delete();
    }

    public function find(Region $region): Region
    {
        return $region->load('parent')->loadCount(['children', 'ordersAsRegion', 'ordersAsDistrict']);
    }

    /**
     * Depth cap: parent must be a root (parent_id null). Null parent = new root.
     * When moving an existing root that has children, refuse becoming a district.
     */
    private function assertValidParent(mixed $parentId, ?Region $region = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Region::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent region does not exist.'],
            ]);
        }

        if (! $parent->isRoot()) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent must be a top-level region (depth is capped at 2 levels).'],
            ]);
        }

        if ($region !== null && $region->isRoot() && $region->children()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['Cannot nest a region that already has districts.'],
            ]);
        }
    }

    private function regionHasOrders(Region $region): bool
    {
        return Order::query()
            ->where('region_id', $region->id)
            ->orWhere('district_id', $region->id)
            ->exists();
    }
}
