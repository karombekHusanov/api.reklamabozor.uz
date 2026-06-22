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
