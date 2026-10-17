<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategoryType;
use App\Http\Controllers\ApiController;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends ApiController
{
    /**
     * Public (authenticated) catalogue of active categories, used by the mini
     * app order form, agent profile and marketplace filters. Filterable by
     * type (agent|designer).
     *
     * Default shape: a flat list in tree order (parents before children, each
     * row with `parent_id` / `depth` / `path_ids` / `full_name_*`). `?tree=1`
     * nests sub-categories under `children` instead. Either way a node is
     * listed only when it and all its ancestors are active.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(CategoryType::class)],
            'tree' => ['nullable', 'boolean'],
        ]);

        $active = Category::query()
            ->active()
            ->when(
                isset($validated['type']),
                fn ($query) => $query->where('type', $validated['type']),
            )
            ->get();

        // A node is visible only when every ancestor is active too.
        $activeIds = $active->pluck('id')->flip();
        $active = $active->filter(function (Category $category) use ($activeIds): bool {
            foreach ($category->ancestorIds() as $id) {
                if (! $activeIds->has($id)) {
                    return false;
                }
            }

            return true;
        })->values();

        $roots = Category::assembleTree($active);

        if (! empty($validated['tree'])) {
            return $this->success(CategoryResource::collection($roots));
        }

        return $this->success(CategoryResource::collection(Category::flattenTree($roots)));
    }
}
