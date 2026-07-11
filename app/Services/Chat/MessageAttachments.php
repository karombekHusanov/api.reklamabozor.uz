<?php

namespace App\Services\Chat;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shared attachment rules for chat messages (order chats + global chat):
 * resolve ids to files the sender actually owns, keep the picked order.
 */
final class MessageAttachments
{
    public const MAX_PER_MESSAGE = 10;

    /**
     * Resolve attachment ids to Files the caller owns, preserving the
     * requested order. Guards against attaching someone else's upload by id.
     *
     * @param  list<int>  $fileIds
     * @return Collection<int, File>
     */
    public static function resolve(User $user, array $fileIds): Collection
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
                    'file_ids' => ['Invalid attachment.'],
                ]);
            }

            $resolved->push($file);
        }

        return $resolved;
    }

    /**
     * Attach resolved files to a message's pivot, keeping the picked order.
     *
     * @param  BelongsToMany<File, covariant \Illuminate\Database\Eloquent\Model>  $relation
     * @param  Collection<int, File>  $files
     */
    public static function attach(BelongsToMany $relation, Collection $files): void
    {
        foreach ($files->values() as $position => $file) {
            $relation->attach($file->id, ['position' => $position]);
        }
    }
}
