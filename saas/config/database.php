<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        // Ruolo dell'applicazione: richieste web e code. Non è proprietario delle tabelle, non ha
        // BYPASSRLS ed è soggetto alla Row-Level Security.
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'as_central'),
            'username' => env('DB_USERNAME', 'as_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // Stesso fuso dell'applicazione (config/app.php, Europe/Rome come il server): se i due fusi differiscono, Laravel scrive
            // gli orari senza indicare il fuso e PostgreSQL li legge in un altro: gli orari slittano e i link di reimpostazione scadono subito.
            'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
            'application_name' => 'associazionesemplice',
        ],

        // Proprietario delle tabelle: solo migrazioni (php artisan saas:migrate). Mai nelle richieste.
        'pgsql_owner' => [
            'driver' => 'pgsql',
            'url' => env('DB_OWNER_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'as_central'),
            'username' => env('DB_OWNER_USERNAME', 'as_owner'),
            'password' => env('DB_OWNER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
            'application_name' => 'associazionesemplice-migrazioni',
            // Ruolo a cui le migrazioni concedono i permessi (OrgSchema::grant).
            'app_role' => env('DB_USERNAME', 'as_app'),
            // Ruolo dei backup: sola lettura, ammesso dalle policy (OrgSchema::secure).
            'backup_role' => env('DB_BACKUP_USERNAME', 'as_backup'),
        ],

        // Sola lettura per pg_dump (saas:backup:dump). Mai nelle richieste.
        'pgsql_backup' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'as_central'),
            'username' => env('DB_BACKUP_USERNAME', 'as_backup'),
            'password' => env('DB_BACKUP_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
        ],

        // Database di prova per il ripristino (saas:backup:restore-test): si svuota a ogni prova.
        'pgsql_restore' => [
            'driver' => 'pgsql',
            'host' => env('DB_RESTORE_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_RESTORE_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_RESTORE_DATABASE', 'as_restore'),
            'username' => env('DB_RESTORE_USERNAME', env('DB_OWNER_USERNAME', 'as_owner')),
            'password' => env('DB_RESTORE_PASSWORD', env('DB_OWNER_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'timezone' => env('APP_TIMEZONE', 'Europe/Rome'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
