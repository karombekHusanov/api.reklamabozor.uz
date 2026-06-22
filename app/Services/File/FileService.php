<?php

namespace App\Services\File;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Central entry point for persisting and removing uploaded files.
 * Every file in the system is stored through this service so the storage
 * disk, directory layout, and DB registry stay consistent in one place.
 */
class FileService
{
    public function store(UploadedFile $file, ?int $uploadedBy = null, ?string $directory = null): File
    {
        $disk = (string) config('files.disk');
        $directory ??= (string) config('files.directory');

        $path = $file->store($directory, $disk);

        return File::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function delete(File $file): void
    {
        Storage::disk($file->disk)->delete($file->path);

        $file->delete();
    }
}
