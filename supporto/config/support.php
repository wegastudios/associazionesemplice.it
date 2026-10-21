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

    // Validita del link inviato al cliente per seguire il ticket
    'customer_link_days' => (int) env('SUPPORT_CUSTOMER_LINK_DAYS', 60),
];
