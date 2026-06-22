<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\FileUploadRequest;
use App\Http\Resources\FileResource;
use App\Services\File\FileService;
use Illuminate\Http\JsonResponse;

class FileUploadController extends ApiController
{
    public function __construct(
        private readonly FileService $files,
    ) {}

    public function store(FileUploadRequest $request): JsonResponse
    {
        $file = $this->files->store(
            $request->file('file'),
            $request->user()->id,
        );

        return $this->success(new FileResource($file), 'File uploaded', 201);
    }
}
