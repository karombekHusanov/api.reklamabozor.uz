<?php

namespace App\Services\Hashtag;

use App\Models\Hashtag;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HashtagService
{
    /**
     * Normalize raw user input into a slug + display label pair, or null if empty.
     *
     * @return array{slug: string, label: string}|null
     */
    public function normalize(string $raw): ?array
    {
        $trimmed = trim($raw);
        $trimmed = ltrim($trimmed, "# \t");
        $trimmed = mb_strtolower($trimmed);
        $trimmed = preg_replace('/[\s_]+/u', '-', $trimmed) ?? '';
        // Keep latin + cyrillic letters, digits, hyphen.
        $trimmed = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $trimmed) ?? '';
        $trimmed = preg_replace('/-+/', '-', $trimmed) ?? '';
        $trimmed = trim($trimmed, '-');

        if ($trimmed === '') {
            return null;
        }

        $slug = mb_substr($trimmed, 0, 40);
        $label = $slug;

        return ['slug' => $slug, 'label' => $label];
    }

    /**
     * Find or create an active hashtag by slug. Reactivates inactive rows.
     *
     * @param  array{slug: string, label: string}  $normalized
     */
    public function findOrCreate(array $normalized): Hashtag
    {
        /** @var Hashtag $hashtag */
        $hashtag = Hashtag::query()->firstOrNew(['slug' => $normalized['slug']]);

        if (! $hashtag->exists) {
            $hashtag->label = $normalized['label'];
            $hashtag->is_active = true;
            $hashtag->usage_count = 0;
            $hashtag->save();

            return $hashtag;
        }

        if (! $hashtag->is_active) {
            $hashtag->is_active = true;
            $hashtag->save();
        }

        return $hashtag;
    }

    /**
     * Attach hashtags to a new order (create-time only). Increments usage_count
     * for each newly attached tag. Empty input is a no-op.
     *
     * @param  list<string>  $rawTags
     */
    public function syncForOrder(Order $order, array $rawTags): void
    {
        $bySlug = [];
        foreach ($rawTags as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $normalized = $this->normalize($raw);
            if ($normalized === null) {
                continue;
            }
            $bySlug[$normalized['slug']] = $normalized;
        }

        if (count($bySlug) > Order::MAX_HASHTAGS) {
            throw ValidationException::withMessages([
                'hashtags' => ['An order may have at most '.Order::MAX_HASHTAGS.' hashtags.'],
            ]);
        }

        if ($bySlug === []) {
            return;
        }

        $ids = [];
        foreach ($bySlug as $normalized) {
            $ids[] = $this->findOrCreate($normalized)->id;
        }

        $order->hashtags()->sync($ids);

        Hashtag::query()->whereIn('id', $ids)->increment('usage_count');
    }

    /**
     * Merge source hashtag into target: move pivots, recompute usage, deactivate source.
     */
    public function merge(Hashtag $source, Hashtag $target): Hashtag
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'target_id' => ['Cannot merge a hashtag into itself.'],
            ]);
        }

        return DB::transaction(function () use ($source, $target): Hashtag {
            $sourceOrderIds = $source->orders()->pluck('orders.id');

            foreach ($sourceOrderIds as $orderId) {
                // Attach target if not already linked; detach source.
                $target->orders()->syncWithoutDetaching([$orderId]);
            }

            $source->orders()->detach();

            $source->is_active = false;
            $source->usage_count = 0;
            $source->save();

            $target->usage_count = $target->orders()->count();
            if (! $target->is_active) {
                $target->is_active = true;
            }
            $target->save();

            return $target->fresh();
        });
    }
}
