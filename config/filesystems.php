<?php

declare(strict_types=1);

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
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'backups' => [
            'driver' => env('MANAGER_BACKUP_DRIVER', 'local'),
            'root' => storage_path('app/private/backups'),
            'visibility' => 'private',
            'throw' => true,

            // Used when MANAGER_BACKUP_DRIVER is s3. Separate credentials from any other bucket, so
            // that a key with access to backups is not also a key with access to anything else.
            'key' => env('MANAGER_BACKUP_S3_KEY'),
            'secret' => env('MANAGER_BACKUP_S3_SECRET'),
            'region' => env('MANAGER_BACKUP_S3_REGION'),
            'bucket' => env('MANAGER_BACKUP_S3_BUCKET'),
            'endpoint' => env('MANAGER_BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('MANAGER_BACKUP_S3_PATH_STYLE', false),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
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

    /*
    |---------------------------------------------------------------------------------------------
    | Backup artifacts
    |---------------------------------------------------------------------------------------------
    |
    | Outside the public disk and outside anything served over HTTP. An artifact is only ever read
    | back through the platform, where the request can be authorised and audited; there is no URL
    | that reaches one.
    |
    | Point this at an S3-compatible bucket in production. The local default exists so a self-hosted
    | installation works before somebody has decided where backups should live, not because a single
    | server is the right place to keep them.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
