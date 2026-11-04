<?php

declare(strict_types=1);

return [
    // Niente rendering lato server: sul server non c'è Node.
    'ssr' => [
        'enabled' => false,
    ],

    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/pages')],
        'extensions' => ['vue'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

    'expose_shared_prop_keys' => true,
    'store_previous_url' => false,

    // La cronologia del browser conserva i dati delle pagine: con i dati dei soci va cifrata.
    'history' => [
        'encrypt' => true,
    ],

    // Gli strumenti di sviluppo di Inertia salvano richieste e dati su disco: mai attivi.
    'devtools' => [
        'enabled' => false,
    ],

    'preserve_big_integers' => false,
];
