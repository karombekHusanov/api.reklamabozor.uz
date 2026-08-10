<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Resources\HashtagResource;
use App\Models\Hashtag;
use App\Services\Hashtag\HashtagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for the order-hashtag catalog. Soft-deactivate preferred over
 * hard delete; merge consolidates duplicates for analytics.
 */
class HashtagController extends ApiController
{
    public function __construct(
        private readonly HashtagService $hashtags,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'string', Rule::in(['usage', 'slug', 'recent'])],
            'active' => ['nullable', 'boolean'],
        ]);

        $query = Hashtag::query();

        if (array_key_exists('active', $validated)) {
            $query->where('is_active', (bool) $validated['active']);
        }

        $q = trim((string) ($validated['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw('LOWER(slug) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(label) LIKE ?', [$like]);
            });
        }

        match ($validated['sort'] ?? 'usage') {
            'slug' => $query->orderBy('slug'),
            'recent' => $query->latest('id'),
            default => $query->orderByDesc('usage_count')->orderBy('slug'),
        };

        $hashtags = $query->get();

        return $this->success(
            $hashtags->map(fn (Hashtag $tag) => [
                ...(new HashtagResource($tag))->toArray($request),
                'usage_count' => (int) $tag->usage_count,
                'is_active' => (bool) $tag->is_active,
            ]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'slug' => ['nullable', 'string', 'max:40', 'unique:hashtags,slug'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $normalized = $this->hashtags->normalize($data['label']);
        if ($normalized === null) {
            return $this->error('Invalid hashtag label', 422);
        }

        $slug = $data['slug'] ?? $normalized['slug'];
        $slugNormalized = $this->hashtags->normalize($slug);
        if ($slugNormalized === null) {
            return $this->error('Invalid hashtag slug', 422);
        }

        $hashtag = Hashtag::query()->create([
            'slug' => $slugNormalized['slug'],
            'label' => $normalized['label'],
            'usage_count' => 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success([
            ...(new HashtagResource($hashtag))->toArray($request),
            'usage_count' => 0,
            'is_active' => (bool) $hashtag->is_active,
        ], 'Hashtag created', 201);
    }

    public function update(Request $request, Hashtag $hashtag): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['label'])) {
            $label = trim(ltrim($data['label'], "# \t"));
            if ($label === '') {
                return $this->error('Invalid hashtag label', 422);
            }
            $hashtag->label = mb_substr($label, 0, 40);
        }

        if (array_key_exists('is_active', $data)) {
            $hashtag->is_active = (bool) $data['is_active'];
        }

        $hashtag->save();

        return $this->success([
            ...(new HashtagResource($hashtag))->toArray($request),
            'usage_count' => (int) $hashtag->usage_count,
            'is_active' => (bool) $hashtag->is_active,
        ], 'Hashtag updated');
    }

    public function destroy(Hashtag $hashtag): JsonResponse
    {
        $hashtag->is_active = false;
        $hashtag->save();

        return $this->success([
            ...(new HashtagResource($hashtag))->toArray(request()),
            'usage_count' => (int) $hashtag->usage_count,
            'is_active' => false,
        ], 'Hashtag deactivated');
    }

    public function merge(Request $request, Hashtag $hashtag): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer', 'exists:hashtags,id'],
        ]);

        /** @var Hashtag $target */
        $target = Hashtag::query()->findOrFail($data['target_id']);
        $merged = $this->hashtags->merge($hashtag, $target);

        return $this->success([
            ...(new HashtagResource($merged))->toArray($request),
            'usage_count' => (int) $merged->usage_count,
            'is_active' => (bool) $merged->is_active,
        ], 'Hashtags merged');
    }
}
