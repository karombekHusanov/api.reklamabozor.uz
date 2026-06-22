<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\IndexCategoriesRequest;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\ToggleCategoryActiveRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Admin\CategoryAdminService;
use Illuminate\Http\JsonResponse;

class CategoryController extends ApiController
{
    public function __construct(
        private readonly CategoryAdminService $categoryAdminService,
    ) {}

    public function index(IndexCategoriesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paginator = $this->categoryAdminService->list([
            'type' => $validated['type'] ?? null,
            'search' => $validated['search'] ?? null,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : null,
            'per_page' => $validated['per_page'] ?? 15,
            'sort' => $validated['sort'] ?? 'sort_order',
            'direction' => $validated['direction'] ?? 'asc',
        ]);

        return $this->success([
            'items' => CategoryResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryAdminService->create($request->validated());

        return $this->success(new CategoryResource($category), 'Category created', 201);
    }

    public function show(Category $category): JsonResponse
    {
        return $this->success(new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categoryAdminService->update($category, $request->validated());

        return $this->success(new CategoryResource($updated), 'Category updated');
    }

    public function toggleActive(ToggleCategoryActiveRequest $request, Category $category): JsonResponse
    {
        $isActive = (bool) $request->validated('is_active');

        $updated = $this->categoryAdminService->setActive($category, $isActive);

        $message = $isActive ? 'Category activated' : 'Category deactivated';

        return $this->success(new CategoryResource($updated), $message);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->categoryAdminService->delete($category);

        return $this->success(null, 'Category deleted');
    }
}
