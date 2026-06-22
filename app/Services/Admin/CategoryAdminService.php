<?php

namespace App\Services\Admin;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CategoryAdminService
{
    /**
     * @param  array{
     *     type?: string|null,
     *     search?: string|null,
     *     is_active?: bool|null,
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
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Category
    {
        if (isset($data['type'])) {
            $data['type'] = CategoryType::from($data['type']);
        }

        return Category::query()->create([
            'name_uz' => $data['name_uz'],
            'name_ru' => $data['name_ru'],
            'type' => $data['type'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Category $category, array $data): Category
    {
        if (isset($data['type'])) {
            $data['type'] = CategoryType::from($data['type']);
        }

        $category->fill($data);
        $category->save();

        return $category->refresh();
    }

    public function setActive(Category $category, bool $isActive): Category
    {
        $category->is_active = $isActive;
        $category->save();

        return $category->refresh();
    }

    public function delete(Category $category): void
    {
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
}
