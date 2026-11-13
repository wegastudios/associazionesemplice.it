<?php

$list = fn (?string $v): array => array_values(array_filter(array_map('trim', explode(',', (string) $v))));

return [
    // Ruolo di questa istanza: "hub" (portale centrale) oppure "site" (sito di supporto di un portale)
    'role' => in_array(env('APP_ROLE'), ['hub', 'site'], true) ? env('APP_ROLE') : 'site',

    // Epoca degli id dei ticket: secondi trascorsi da questa data (UTC)
    'ticket_epoch' => env('SUPPORT_TICKET_EPOCH', '2023-12-27T00:00:00Z'),

    // Priorita centrale: 0 minima ... 4 massima
    'priority_levels' => [0, 1, 2, 3, 4],
    'priority_labels' => [0 => 'Minima', 1 => 'Bassa', 2 => 'Normale', 3 => 'Alta', 4 => 'Massima'],
    'default_priority' => 2,

    // Dalla priorita indicata in poi l'hub invia le email di avviso
    'notify_from_priority' => 3,

    // Destinatari sempre avvisati per le priorita alte (oltre a quelli di impostazioni e portale)
    'notify_emails' => $list(env('SUPPORT_NOTIFY_EMAILS', 'info@wegastudios.com')),

    // Stati centrali dei ticket
    'statuses' => ['open', 'pending', 'on_hold', 'solved', 'closed'],

    // Durata della finestra di rotazione di un segreto
    'secret_rotation_hours' => 24,

    // Collegamento del sito all'hub (solo ruolo "site"): credenziali ottenute dalla registrazione nell'hub
    'hub' => [
        'url' => rtrim((string) env('SUPPORT_HUB_URL', ''), '/'),
        'token' => env('SUPPORT_HUB_TOKEN'),
        'timeout' => (int) env('SUPPORT_HUB_TIMEOUT', 8),
    ],

    // Informativa privacy accettata dal cliente all'apertura del ticket: indirizzo e versione (da aumentare quando il testo cambia)
    'privacy' => [
        'url' => env('SUPPORT_PRIVACY_URL', 'https://associazionesemplice.it/privacy'),
        'version' => env('SUPPORT_PRIVACY_VERSION', '1'),
    ],

    // Accesso unico dal portale (stesso segreto in config.php del portale, almeno 32 caratteri): senza segreto l'accesso unico e spento
    'sso' => [
        'secret' => env('SUPPORT_SSO_SECRET'),
        'portal' => rtrim((string) env('SUPPORT_SSO_PORTAL', 'https://associazionesemplice.it'), '/'),
    ],

    // Accesso unico dello STAFF: token firmato (EdDSA) emesso dal portale, vedi docs/STAFF_SSO.md.
    // Le chiavi pubbliche del portale sono "kid:x" separate da virgola; la chiave privata del supporto sta in un file fuori dal repository
    // (si crea con `php artisan staff:key`). Senza chiavi del portale l'accesso staff unico e spento.
    'staff_sso' => [
        // Chi emette i token e risponde a riconferma e «Utenti» (oggi il SaaS, con un indirizzo del dominio principale); se manca, il portale
        'issuer' => rtrim((string) env('SUPPORT_STAFF_ISSUER', env('SUPPORT_SSO_PORTAL', 'https://associazionesemplice.it')), '/'),
        'portal_keys' => env('SUPPORT_STAFF_PORTAL_KEYS'),
        'audience' => 'associazionesemplice-supporto',
        // Destinatario delle richieste firmate del supporto (riconferma, «Utenti», login, «Vai a»)
        'request_audience' => 'associazionesemplice-saas',
        'private_key_file' => env('SUPPORT_STAFF_PRIVATE_KEY_FILE', storage_path('app/private/staff-sign.key')),
        'idle_minutes' => 120,
        'max_hours' => 8,
        'check_seconds' => 300,
        // Accesso locale dello staff (email e password): resta acceso finche l'accesso unico non e verificato, poi si spegne da .env.
        // Spento, resta solo come accesso di emergenza della titolare, riacceso dal server.
        // Login nel modulo del supporto verificato dal SaaS (POST /api/staff/v1/login): email, password e codice non si salvano qui
        'remote_login' => (bool) env('SUPPORT_STAFF_REMOTE_LOGIN', false),
        'local_login' => (bool) env('SUPPORT_STAFF_LOCAL_LOGIN', true),
        // Accesso e uscita sono del portale (/accedi, /account/esci): acceso, il supporto non ha moduli propri per password, email o secondo livello
        // e rinvia al portale. Si accende quando /accedi del portale esiste; fino ad allora restano i moduli attuali.
        'portal_login' => (bool) env('SUPPORT_STAFF_PORTAL_LOGIN', false),
        'login_url' => env('SUPPORT_STAFF_LOGIN_URL'),
        'logout_url' => env('SUPPORT_STAFF_LOGOUT_URL'),
    ],

    // Accesso dei CLIENTI dal SaaS (token customer_sso EdDSA, stesso emittente e destinatario dello staff). Chiavi pubbliche "kid:x" `cust-…`,
    // separate da quelle dello staff; senza chiavi l'accesso dal SaaS e spento (resta il ripiego HMAC di 'sso').
    'customer_sso' => [
        'keys' => env('SUPPORT_CUSTOMER_KEYS'),
    ],

    // Nomi dei piani nei testi delle guide: si cambiano qui (o da .env) e si rilancia il seeder, mai a mano nei testi
    'plan_names' => [
        'contabile' => env('SUPPORT_PLAN_CONTABILE', 'PRO Contabilità'),
        'fiscale' => env('SUPPORT_PLAN_FISCALE', 'PRO Contabilità & Fiscalità'),
        // Piani del gestionale online (il catalogo definitivo è del portale: qui solo i nomi usati nelle guide)
        'piccola' => env('SUPPORT_PLAN_PICCOLA', 'Piccola'),
        'media' => env('SUPPORT_PLAN_MEDIA', 'Media'),
        'grande' => env('SUPPORT_PLAN_GRANDE', 'Grande'),
    ],

    // Validita del link inviato al cliente per seguire il ticket
    'customer_link_days' => (int) env('SUPPORT_CUSTOMER_LINK_DAYS', 60),
];
