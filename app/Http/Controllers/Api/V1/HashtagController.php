<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Resources\HashtagResource;
use App\Models\Hashtag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HashtagController extends ApiController
{
    /**
     * Autocomplete / suggest active hashtags (usage_count desc).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $limit = (int) ($validated['limit'] ?? 15);
        $q = trim((string) ($validated['q'] ?? ''));

        $query = Hashtag::query()
            ->active()
            ->orderByDesc('usage_count')
            ->orderBy('slug');

        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw('LOWER(slug) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(label) LIKE ?', [$like]);
            });
        }

        $hashtags = $query->limit($limit)->get();

        return $this->success(
            $hashtags->map(fn (Hashtag $tag) => [
                ...(new HashtagResource($tag))->toArray($request),
                'usage_count' => (int) $tag->usage_count,
            ]),
        );
    }
}
