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
    'is_private',
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
     * Path for the stored file, relative to the API host. Public files resolve
     * to the web-served disk (e.g. "/storage/uploads/foo.jpg"); private ones
     * (KYC scans, contracts, invoices, acts) to the authenticated stream
     * endpoint ("/api/v1/files/{id}", Bearer token required). The client
     * prepends its own base URL, so links never bake in a server-side host.
     */
    public function url(): string
    {
        if ($this->is_private) {
            return route('files.show', $this, absolute: false);
        }

        $url = Storage::disk($this->disk)->url($this->path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /**
     * Absolute URL (host included) for server-side consumers that fetch the
     * file over HTTP — e.g. Telegram, which downloads documents/photos from a
     * public URL. Built from the app/disk host (APP_URL), not the client base.
     * Private files resolve to the authenticated endpoint, which an anonymous
     * fetcher cannot read — never hand those to Telegram.
     */
    public function absoluteUrl(): string
    {
        if ($this->is_private) {
            return route('files.show', $this);
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Whether the bytes are present on the file's disk.
     */
    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_private' => 'boolean',
        ];
    }
}
