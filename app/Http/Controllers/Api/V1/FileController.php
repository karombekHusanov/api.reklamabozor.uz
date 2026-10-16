<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Models\File;
use App\Services\File\FileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated stream for private files (KYC scans, contracts, invoices,
 * acts). Public files are served by nginx from /storage and never need this,
 * but the endpoint accepts them too so one URL shape works for every file.
 */
class FileController extends ApiController
{
    public function __construct(
        private readonly FileAccessService $access,
    ) {}

    public function show(Request $request, File $file): Response
    {
        if (! $this->access->canView($request->user(), $file)) {
            return $this->error('You may not access this file.', 403);
        }

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            return $this->error('File not found.', 404);
        }

        return $disk->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
