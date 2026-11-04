<?php

declare(strict_types=1);

return [

    'db' => [
        // Database condiviso: connessione dell'applicazione e del proprietario (config/database.php).
        'app_connection' => 'pgsql',
        'owner_connection' => 'pgsql_owner',

        // Elenco dei database dedicati (pacchetto Database dedicato), fuori dal repository e dalla cartella
        // pubblica. Formato in App\Modules\Core\Database\DatabaseRegistry.
        'dedicated_file' => env('SAAS_DEDICATED_DB_FILE'),
    ],

    'crypto' => [
        // remote = servizio chiavi su un account separato (produzione); local = chiave principale nel file
        // d'ambiente (sviluppo, test e ripiego).
        'vault' => env('SAAS_KEY_VAULT', 'local'),
        'master_key' => env('SAAS_MASTER_KEY'),
        'service_url' => env('SAAS_KEY_SERVICE_URL'),
        'signing_key' => env('SAAS_KEY_SERVICE_SIGNING_KEY'),
        'timeout' => env('SAAS_KEY_SERVICE_TIMEOUT', '3'),
        // Quanto resta in memoria (APCu) una chiave sbloccata, in secondi.
        'cache_ttl' => 300,
    ],

];
