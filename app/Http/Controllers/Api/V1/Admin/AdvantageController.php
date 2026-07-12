<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Resources\AdvantageResource;
use App\Models\Advantage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin CRUD for the provider-advantages catalog. Deleting an in-use entry
 * detaches it from profiles via the pivot's cascade.
 */
class AdvantageController extends ApiController
{
    public function index(): JsonResponse
    {
        $advantages = Advantage::query()
            ->withCount('agentProfiles')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success($advantages->map(fn (Advantage $advantage) => [
            ...(new AdvantageResource($advantage))->toArray(request()),
            'used_by_count' => (int) $advantage->agent_profiles_count,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $advantage = Advantage::create($this->validated($request));

        return $this->success(new AdvantageResource($advantage), 'Advantage created', 201);
    }

    public function update(Request $request, Advantage $advantage): JsonResponse
    {
        $advantage->update($this->validated($request, updating: true));

        return $this->success(new AdvantageResource($advantage), 'Advantage updated');
    }

    public function destroy(Advantage $advantage): JsonResponse
    {
        $advantage->delete();

        return $this->success(null, 'Advantage deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name_uz' => [$presence, 'string', 'max:80'],
            'name_ru' => [$presence, 'string', 'max:80'],
            'hint_uz' => ['sometimes', 'nullable', 'string', 'max:160'],
            'hint_ru' => ['sometimes', 'nullable', 'string', 'max:160'],
            'icon' => [$presence, 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
