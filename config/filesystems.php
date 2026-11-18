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

    'default' => env('FILESYSTEM_DRIVER', 's3'),

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

        'public-media-storage' => [
            'driver' => 'local',
            'root' => public_path(),
            'url' => env('APP_URL'),
            'visibility' => 'public',
        ],
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID') ? trim(env('AWS_ACCESS_KEY_ID')) : '12f173905263a32ecc4d8ecf23571270',
            'secret' => env('AWS_SECRET_ACCESS_KEY') ? trim(env('AWS_SECRET_ACCESS_KEY')) : '87f60a4f4ab7b9c09a33694efaaeb91900bb7c693b0192542103834537bd935a',
            'region' => env('AWS_DEFAULT_REGION') ? trim(env('AWS_DEFAULT_REGION')) : 'ap-south-1',
            'bucket' => env('AWS_BUCKET') ? trim(env('AWS_BUCKET')) : 'adscale-media',
            'url' => env('AWS_URL') ? trim(env('AWS_URL')) : ('https://qtzfdnyalnkdspywpjoq.supabase.co/storage/v1/object/public/' . (env('AWS_BUCKET') ? trim(env('AWS_BUCKET')) : 'adscale-media')),
            'endpoint' => env('AWS_ENDPOINT') ? trim(env('AWS_ENDPOINT')) : 'https://qtzfdnyalnkdspywpjoq.storage.supabase.co/storage/v1/s3',
            'use_path_style_endpoint' => true,
            'throw' => false,
        ],

        'do_s3_space' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'region' => env('DO_SPACES_REGION'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'url' => env('DO_FULL_URL'),
            'full_url' => env('DO_FULL_URL'),
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
