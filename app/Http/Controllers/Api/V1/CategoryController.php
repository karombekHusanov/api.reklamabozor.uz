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
     * Public (authenticated) list of active categories, used by the mini app
     * agent application form. Filterable by type (agent|designer).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(CategoryType::class)],
        ]);

        $categories = Category::query()
            ->active()
            ->when(
                isset($validated['type']),
                fn ($query) => $query->where('type', $validated['type']),
            )
            ->orderBy('sort_order')
            ->orderBy('name_uz')
            ->get();

        return $this->success(CategoryResource::collection($categories));
    }
}
