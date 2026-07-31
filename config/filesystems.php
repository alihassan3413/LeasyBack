<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET', env('S3_BUCKET_NAME')),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Private customer/vehicle documents (leasing contracts, damage
         * photos, appraisal reports, invoices, ...). Never referenced by
         * name ('local'/'s3') anywhere outside this file — application code
         * always calls Storage::disk('documents'), so switching production
         * to S3 later is a one-line env change (DOCUMENTS_FILESYSTEM_DRIVER=s3
         * plus the existing AWS_* vars below), not a code change. Every
         * read/delete still goes through a document's own DB record + a
         * Policy first; the disk swap doesn't touch that.
         */
        'documents' => [
            'driver' => env('DOCUMENTS_FILESYSTEM_DRIVER', 'local'),
            'root' => storage_path('app/private/documents'),
            // Distinct URI prefix so this disk's signed-URL route doesn't
            // collide with the default 'local' disk's /storage route — both
            // have `serve` enabled. Only relevant while driver=local; the s3
            // driver ignores 'serve'/'url' and returns a real presigned URL.
            'url' => '/private-documents',
            'serve' => true,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
            // Only read when the driver above is 's3' — reuses the same
            // credentials as the generic 's3' disk, not a second secret set.
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('DOCUMENTS_S3_BUCKET', env('AWS_BUCKET', env('S3_BUCKET_NAME'))),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
