<?php

namespace App\Services\Agent;

use App\Models\AgentPortfolioItem;
use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolve + sync portfolio gallery images and file attachments.
 * Keeps image_file_id in sync with the first gallery image (cover).
 */
final class PortfolioItemFiles
{
    public const MAX_IMAGES = 10;

    public const MAX_ATTACHMENTS = 5;

    /**
     * @param  list<int>  $fileIds
     * @return Collection<int, File>
     */
    public static function resolve(User $user, array $fileIds, string $field): Collection
    {
        if ($fileIds === []) {
            return new Collection;
        }

        $files = File::query()->whereIn('id', $fileIds)->get()->keyBy('id');
        $resolved = new Collection;

        foreach (array_values(array_unique($fileIds)) as $id) {
            $file = $files->get($id);

            if ($file === null || $file->uploaded_by !== $user->id) {
                throw ValidationException::withMessages([
                    $field => ['Invalid file.'],
                ]);
            }

            $resolved->push($file);
        }

        return $resolved;
    }

    /**
     * @param  list<int>  $fileIds
     */
    public static function syncImages(AgentPortfolioItem $item, array $fileIds, User $user): void
    {
        $files = self::resolve($user, $fileIds, 'image_file_ids');

        self::syncPivot($item->imageFiles(), $files);

        $item->update(['image_file_id' => $files->first()->id]);
    }

    /**
     * @param  list<int>  $fileIds
     */
    public static function syncAttachments(AgentPortfolioItem $item, array $fileIds, User $user): void
    {
        $files = self::resolve($user, $fileIds, 'attachment_file_ids');

        self::syncPivot($item->attachmentFiles(), $files);
    }

    /**
     * @param  BelongsToMany<File, covariant \Illuminate\Database\Eloquent\Model>  $relation
     * @param  Collection<int, File>  $files
     */
    private static function syncPivot(BelongsToMany $relation, Collection $files): void
    {
        $relation->detach();

        foreach ($files->values() as $position => $file) {
            $relation->attach($file->id, ['sort_order' => $position]);
        }
    }
}
