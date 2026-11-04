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

    // Contratto con il portale (docs/CONTRATTO_PORTALE.md).
    'portal' => [
        'url' => env('SAAS_PORTAL_URL', 'https://associazionesemplice.it'),
        // Chiavi pubbliche del portale fissate qui: "kid=x,kid2=x2" (x = chiave pubblica Ed25519 in base64url).
        'public_keys' => env('SAAS_PORTAL_PUBLIC_KEYS', ''),
        // Seme della chiave Ed25519 con cui il SaaS firma verso il portale (base64, 32 byte).
        'signing_key' => env('SAAS_PORTAL_SIGNING_KEY'),
        // Giorni dopo la scadenza prima che il SaaS sospenda da sé un'associazione.
        'grace_days' => 15,
    ],

];
