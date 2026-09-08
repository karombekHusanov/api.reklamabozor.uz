<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\StoreMxikCodeRequest;
use App\Http\Resources\AdminMxikCodeResource;
use App\Models\MxikCode;
use App\Services\Fiscal\FiscalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MXIK (IKPU) classifier catalogue — the fiscal codes every pricelist row and
 * every OFD receipt line is built from.
 */
class MxikCodeController extends ApiController
{
    public function __construct(private readonly FiscalService $fiscal) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = MxikCode::query()->with('categories')
            ->orderBy('sort_order')
            ->orderBy('code');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name_uz', 'like', "%{$search}%")
                    ->orWhere('name_ru', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => AdminMxikCodeResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreMxikCodeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);

        $code = DB::transaction(function () use ($data, $categoryIds): MxikCode {
            $code = MxikCode::create($data);

            if ($categoryIds !== null) {
                $this->syncCategories($code, $categoryIds);
            }

            return $code;
        });

        return $this->success(
            new AdminMxikCodeResource($code->load('categories')),
            'Classifier code added',
            201,
        );
    }

    public function update(StoreMxikCodeRequest $request, MxikCode $mxikCode): JsonResponse
    {
        $data = $request->validated();
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);

        DB::transaction(function () use ($mxikCode, $data, $categoryIds): void {
            $mxikCode->update($data);

            if ($categoryIds !== null) {
                $this->syncCategories($mxikCode, $categoryIds);
            }
        });

        return $this->success(
            new AdminMxikCodeResource($mxikCode->fresh()->load('categories')),
            'Classifier code updated',
        );
    }

    /**
     * Deleting a code that categories still default to would silently stop new
     * pricelists from being fiscalised — deactivate it instead.
     */
    public function destroy(MxikCode $mxikCode): JsonResponse
    {
        if ($mxikCode->categories()->exists()) {
            return $this->error('This code is still assigned to a category — unassign it first.', 422);
        }

        $mxikCode->delete();

        return $this->success(null, 'Classifier code deleted');
    }

    /**
     * Which categories have a default code and which are still uncovered — the
     * gap the operator has to close before OFD can be switched on.
     */
    public function coverage(): JsonResponse
    {
        return $this->success($this->fiscal->coverage());
    }

    /**
     * A category has exactly one default code (the pivot is unique on category).
     *
     * @param  list<int>  $categoryIds
     */
    private function syncCategories(MxikCode $code, array $categoryIds): void
    {
        DB::table('category_mxik_defaults')->whereIn('category_id', $categoryIds)->delete();

        $code->categories()->syncWithoutDetaching($categoryIds);

        // Categories dropped from the list lose their default.
        $code->categories()->detach(
            $code->categories()->pluck('categories.id')->diff($categoryIds)->all(),
        );
    }
}
