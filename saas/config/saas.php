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

    'auth' => [
        // Verifica in due passaggi obbligatoria per chi è operatore. Si spegne SOLO in sviluppo, con dati inventati
        // (SAAS_REQUIRE_TWO_FACTOR=false nel file d'ambiente): saas:health lo segnala finché è spenta.
        'require_two_factor' => (bool) env('SAAS_REQUIRE_TWO_FACTOR', true),
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

    // Area soci: https://<slug>.<members_domain>/area (docs/ARCHITETTURA.md §3). In locale si usa per esempio
    // «localhost»: i browser portano <slug>.localhost sul computer stesso.
    'members_domain' => env('SAAS_MEMBERS_DOMAIN', 'associazionesemplice.it'),

    // Percorso dell'amministrazione della piattaforma (docs/PIATTAFORMA.md §4): `admin` qui; dietro il proxy del
    // portale sarà `saas/admin`, con `ASSET_URL` e `APP_URL` coerenti.
    'admin_path' => env('SAAS_ADMIN_PATH', 'saas/admin'),

    // Indirizzi da cui fidarsi degli X-Forwarded-* (il proxy del portale), separati da virgole; vuoto = nessuno.
    'trusted_proxies' => env('SAAS_TRUSTED_PROXIES', ''),

    // Ambiente demo (docs/PIATTAFORMA.md §6): acceso solo sul server demo (APP_ENV=demo), mai in produzione.
    // `saas:demo:reset` cancella il database e lo ricrea con dati finti; gli accessi sono pubblici per ruolo.
    'demo' => [
        'enabled' => (bool) env('SAAS_DEMO', false),
        'org_name' => 'Polisportiva Demo',
        'org_slug' => 'polisportiva-demo',
        // Password pubblica degli utenti di prova (titolare@, segreteria@, commercialista@ su mail_domain).
        'password' => env('SAAS_DEMO_PASSWORD', 'demo-demo-demo-2026'),
        'mail_domain' => env('SAAS_DEMO_MAIL_DOMAIN', 'demo.associazionesemplice.it'),
    ],

    // Accesso unico dello staff (docs/UTENTI_E_ACCESSI.md): /saas/admin si apre con il token `staff_sso` del portale.
    // Spento finché SAAS_STAFF_SSO non è true; spento resta l'accesso locale dello staff.
    'staff_sso' => [
        'enabled' => (bool) env('SAAS_STAFF_SSO', false),
        // Dove mandare chi non ha sessione (vuoto = <portale>/staff/accedi?area=saas).
        'login_url' => env('SAAS_STAFF_LOGIN_URL'),
    ],

    // Il SaaS come fornitore di identità dello staff (docs/UTENTI_E_ACCESSI.md): spento finché SAAS_STAFF_ISSUER non è true.
    'staff_issuer' => [
        'enabled' => (bool) env('SAAS_STAFF_ISSUER', false),
        'issuer' => env('SAAS_STAFF_ISSUER_URL', 'https://associazionesemplice.it/saas'),
        'request_issuer' => env('SAAS_STAFF_REQUEST_ISSUER', 'https://associazionesemplice.it'),
        // Seme Ed25519 (base64, 32 byte) della chiave staff-… che firma gli staff_sso.
        'signing_key' => env('SAAS_STAFF_SIGNING_KEY'),
        // Chiavi pubbliche delle aree: "kid=x@area,…" (area = wordpress|supporto).
        'area_keys' => env('SAAS_STAFF_AREA_KEYS', ''),
        // Email della titolare (admin di tutte le aree, sempre): solo qui, mai nel repository.
        'super_admins' => env('SAAS_STAFF_SUPER_ADMINS', ''),
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

    // Dump orari cifrati (docs/RILASCIO.md). Sul server solo la chiave pubblica; la coppia completa solo dove si fa
    // la prova di ripristino (staging).
    'backup' => [
        'public_key' => env('SAAS_BACKUP_PUBLIC_KEY'),
        'secret_key' => env('SAAS_BACKUP_SECRET_KEY'),
        'pg_dump' => env('SAAS_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('SAAS_PG_RESTORE', 'pg_restore'),
    ],

];
