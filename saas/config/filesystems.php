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

        // Spazio dei backup: in produzione esterno e in UE (SFTP, per esempio uno Storage Box), in locale su disco.
        'backups' => env('SAAS_BACKUP_DRIVER', 'local') === 'sftp' ? [
            'driver' => 'sftp',
            'host' => env('SAAS_BACKUP_SFTP_HOST'),
            'port' => (int) env('SAAS_BACKUP_SFTP_PORT', 22),
            'username' => env('SAAS_BACKUP_SFTP_USERNAME'),
            'privateKey' => env('SAAS_BACKUP_SFTP_KEY_PATH'),
            'hostFingerprint' => env('SAAS_BACKUP_SFTP_FINGERPRINT'),
            'root' => env('SAAS_BACKUP_SFTP_ROOT', '/backups'),
            'timeout' => 30,
            'throw' => true,
        ] : [
            'driver' => 'local',
            'root' => env('SAAS_BACKUP_LOCAL_ROOT', storage_path('app/backups')),
            'throw' => true,
        ],

        // File delle associazioni, già cifrati (Cifratura\Files): fuori dalla cartella pubblica, mai serviti
        // direttamente dal server web.
        'org_files' => [
            'driver' => 'local',
            'root' => (static function (): string {
                $root = env('SAAS_FILES_ROOT');

                return is_string($root) && $root !== '' ? (str_starts_with($root, '/') ? $root : base_path($root)) : storage_path('app/org-files');
            })(),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
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

    // Nessun link pubblico: i file delle associazioni sono cifrati e serviti solo dopo il controllo dei permessi.
    'links' => [],

];
