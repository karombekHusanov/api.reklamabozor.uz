<?php

namespace App\Services\Admin;

use App\Models\Banner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BannerAdminService
{
    /**
     * @param  array{
     *     search?: string|null,
     *     is_active?: bool|null,
     *     per_page?: int,
     *     sort?: string,
     *     direction?: string
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Banner::query()->with('imageFile');

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $likeTerm = '%'.mb_strtolower($filters['search']).'%';

            $query->where(function ($builder) use ($likeTerm): void {
                $builder
                    ->whereRaw('LOWER(title) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(subtitle) LIKE ?', [$likeTerm]);
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
    public function create(array $data): Banner
    {
        $banner = Banner::query()->create([
            'title' => $data['title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'type' => $data['type'],
            'target_id' => $data['target_id'],
            'image_file_id' => $data['image_file_id'],
            'link_url' => $data['link_url'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $banner->load('imageFile');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Banner $banner, array $data): Banner
    {
        $banner->fill($data);
        $banner->save();

        return $banner->refresh()->load('imageFile');
    }

    public function setActive(Banner $banner, bool $isActive): Banner
    {
        $banner->is_active = $isActive;
        $banner->save();

        return $banner->refresh()->load('imageFile');
    }

    public function delete(Banner $banner): void
    {
        $banner->delete();
    }
}
