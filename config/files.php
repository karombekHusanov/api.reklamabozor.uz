<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default upload disk
    |--------------------------------------------------------------------------
    | Filesystem disk (config/filesystems.php) that the FileService writes to.
    */
    'disk' => env('FILES_DISK', 'public'),

    /*
    | Disk for private files (KYC scans, contracts, invoices, acts). Must NOT be
    | web-served: they are streamed through the authenticated GET /files/{file}
    | endpoint, which checks who may see each file (FileAccessService).
    */
    'private_disk' => env('FILES_PRIVATE_DISK', 'local'),

    /*
    | Sub-directory within the disk where uploads are stored.
    */
    'directory' => env('FILES_DIRECTORY', 'uploads'),

    /*
    | Max upload size in kilobytes.
    */
    'max_size' => (int) env('FILES_MAX_SIZE_KB', 5120), // 5 MB

    /*
    | Allowed file extensions for the generic upload endpoint.
    */
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'],

    /*
    | Image-only extensions (e.g. avatars).
    */
    'image_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

];
