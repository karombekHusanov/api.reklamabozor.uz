<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size',
    'uploaded_by',
])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Public path for the stored file, relative to the API host
     * (e.g. "/storage/uploads/foo.jpg"). The client prepends its own base URL,
     * so file links never bake in a server-side host (which may be stale).
     */
    public function url(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /**
     * Absolute public URL (host included) for server-side consumers that fetch
     * the file over HTTP — e.g. Telegram, which downloads documents/photos from
     * a public URL. Built from the app/disk host (APP_URL), not the client base.
     */
    public function absoluteUrl(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
