<?php

declare(strict_types=1);

/*
 * Catalogo del SaaS: piani, pacchetti, prezzi e promozioni (docs/CONTRATTO_PORTALE.md §2, listino in
 * docs/PERIMETRO.md §7.2). È il contenuto iniziale: quando l'amministrazione (/saas/admin) avrà l'editor, il catalogo
 * pubblicato verrà dal database e questo file servirà solo a partire.
 *
 * Regole: importi in centesimi interi, IVA esclusa; una modifica a un piano crea una `plan_version` nuova (mai
 * cambiare una versione già venduta); a ogni modifica `rev` aumenta. Il documento è controllato da
 * App\Modules\Piattaforma\Catalogo\CatalogValidator.
 */

$vat = 22;

// Mensile e annuale (= 10 mensilità) di un prezzo fisso.
$flat = static fn (int $monthlyCents): array => [
    ['period' => 'month', 'kind' => 'flat', 'vat_percent' => 22, 'amount_cents' => $monthlyCents],
    ['period' => 'year', 'kind' => 'flat', 'vat_percent' => 22, 'amount_cents' => $monthlyCents * 10],
];

// Scaglioni marginali sui soci, mensili e annuali.
$memberTiers = static fn (int $baseCents, array $tiers): array => [
    ['period' => 'month', 'kind' => 'members_tiers', 'vat_percent' => 22, 'base_members' => 50, 'base_cents' => $baseCents, 'months_billed' => 1, 'tiers' => $tiers],
    ['period' => 'year', 'kind' => 'members_tiers', 'vat_percent' => 22, 'base_members' => 50, 'base_cents' => $baseCents, 'months_billed' => 10, 'tiers' => $tiers],
];

$package = static fn (string $code, string $name, int $monthlyCents, array $limits = []): array => [
    'code' => $code,
    'name' => $name,
    'kind' => 'package',
    'visible' => true,
    'grants' => ['package' => $code, 'limits' => $limits],
    'prices' => $flat($monthlyCents),
];

$resources = static fn (string $code, string $name, string $kind, int $first, array $steps): array => [
    'code' => $code,
    'name' => $name,
    'kind' => 'package',
    'visible' => true,
    'grants' => ['package' => 'resources', 'limits' => [$kind => ['tier' => $first, 'web' => true]]],
    'prices' => [['period' => 'month', 'kind' => 'step_tiers', 'vat_percent' => 22, 'steps' => $steps]],
];

// Un piano a prezzo fisso per fasce di soci (decisione del 13/11/2026). I pacchetti inclusi stanno in `limits.packages`;
// `$extra` sono le caratteristiche che cambiano da un piano all'altro.
$tier = static fn (string $code, int $order, string $name, string $audience, int $members, int $monthlyCents, int $operators, string $operatorsText, array $extra, array $packages): array => [
    'code' => $code,
    'plan_version' => 1,
    'name' => $name,
    'audience' => $audience,
    'order' => $order,
    'visible' => true,
    'support_level' => 'paid',
    'trial_days' => 30,
    'features' => $extra + [
        'members' => ['state' => 'limit', 'text' => "Fino a {$members} soci"],
        'ledger' => ['state' => 'included'],
        'activities' => ['state' => 'included'],
        'operators' => ['state' => 'limit', 'text' => $operatorsText],
        'storage' => ['state' => 'limit', 'text' => '5 MB per socio, almeno 250 MB'],
        'resources' => ['state' => 'excluded'],
        'site' => ['state' => 'excluded'],
        'advanced_accounting' => ['state' => 'excluded'],
        'fiscal' => ['state' => 'limit', 'text' => 'In arrivo'],
        'sport' => ['state' => 'excluded'],
    ],
    'limits' => [
        'members' => $members,
        'operators' => $operators,
        'storage_mb_per_member' => 5,
        'storage_mb_min' => 250,
        'packages' => $packages,
    ],
    'prices' => $flat($monthlyCents),
];

$catalog = [
    'name' => 'Associazione Semplice · Gestionale',
    'description' => 'Il gestionale per associazioni, circoli e società sportive: soci, prima nota, attività e area soci.',
    'rev' => 2,
    'published_at' => '2026-11-12',
    'currency' => 'EUR',

    'features' => [
        ['id' => 'members', 'text' => 'Anagrafica soci e tesseramento', 'group' => 'Base', 'compare' => 'members'],
        ['id' => 'ledger', 'text' => 'Prima nota e ricevute', 'group' => 'Base', 'compare' => 'ledger'],
        ['id' => 'activities', 'text' => 'Corsi, eventi e presenze', 'group' => 'Base', 'compare' => 'activities'],
        ['id' => 'operators', 'text' => 'Operatori', 'group' => 'Base', 'compare' => 'operators'],
        ['id' => 'storage', 'text' => 'Spazio per i file', 'group' => 'Base'],
        ['id' => 'member_area', 'text' => 'Area soci e pagamenti online', 'group' => 'Pacchetti', 'compare' => 'member_area'],
        ['id' => 'resources', 'text' => 'Risorse e prenotazioni', 'group' => 'Pacchetti', 'compare' => 'resources'],
        ['id' => 'site', 'text' => "Sito dell'associazione", 'group' => 'Pacchetti', 'compare' => 'site'],
        ['id' => 'einvoice', 'text' => 'Fattura elettronica', 'group' => 'Pacchetti', 'compare' => 'einvoice'],
        ['id' => 'communications', 'text' => 'Comunicazioni', 'group' => 'Pacchetti'],
        ['id' => 'advanced_accounting', 'text' => 'Contabilità avanzata', 'group' => 'Pacchetti'],
        ['id' => 'fiscal', 'text' => 'Parte fiscale: IVA e corrispettivi, regime L.398, 5 per mille', 'group' => 'Pacchetti', 'compare' => 'fiscal'],
        ['id' => 'association_life', 'text' => 'Vita associativa', 'group' => 'Pacchetti'],
        ['id' => 'sport', 'text' => 'Sport', 'group' => 'Pacchetti'],
    ],

    'plans' => [
        [
            'code' => 'gestionale',
            'plan_version' => 1,
            'name' => 'Gestionale',
            'audience' => 'Associazioni, APS, ODV e società sportive',
            'order' => 1,
            'visible' => true,
            'support_level' => 'paid',
            'trial_days' => 30,
            'features' => [
                'members' => ['state' => 'included'],
                'ledger' => ['state' => 'included'],
                'activities' => ['state' => 'included'],
                'operators' => ['state' => 'limit', 'text' => '1 incluso, altri a 3 € al mese'],
                'storage' => ['state' => 'limit', 'text' => '5 MB a socio (minimo 250 MB)'],
                'member_area' => ['state' => 'excluded'],
                'resources' => ['state' => 'excluded'],
                'site' => ['state' => 'excluded'],
                'einvoice' => ['state' => 'excluded'],
                'communications' => ['state' => 'excluded'],
                'advanced_accounting' => ['state' => 'excluded'],
                'fiscal' => ['state' => 'excluded'],
                'association_life' => ['state' => 'excluded'],
                'sport' => ['state' => 'excluded'],
            ],
            'limits' => [
                'members' => null,
                'operators' => 1,
                'storage_mb_per_member' => 5,
                'storage_mb_min' => 250,
                'packages' => [],
            ],
            // I primi 50 soci a 15 € fissi, poi 0,18 · 0,12 · 0,06 € a socio al mese (PERIMETRO §7.2).
            'prices' => $memberTiers(1500, [['up_to' => 150, 'unit_cents' => 18], ['up_to' => 400, 'unit_cents' => 12], ['up_to' => null, 'unit_cents' => 6]]),
        ],
        $tier('piccola', 1, 'Piccola', 'Associazioni fino a 100 soci', 100, 1900, 2, '2 operatori', [
            'member_area' => ['state' => 'excluded'],
            'einvoice' => ['state' => 'excluded'],
            'communications' => ['state' => 'excluded'],
            'association_life' => ['state' => 'excluded'],
        ], []),
        $tier('media', 2, 'Media', 'Associazioni fino a 300 soci', 300, 3500, 5, '5 operatori', [
            'member_area' => ['state' => 'included'],
            'einvoice' => ['state' => 'limit', 'text' => "50 fatture all'anno"],
            'communications' => ['state' => 'excluded'],
            'association_life' => ['state' => 'excluded'],
        ], ['member_area' => [], 'einvoice' => ['yearly' => 50]]),
        $tier('grande', 3, 'Grande', 'Associazioni fino a 1.000 soci', 1000, 5900, 1000, 'Senza limite', [
            'member_area' => ['state' => 'included'],
            'einvoice' => ['state' => 'limit', 'text' => "50 fatture all'anno"],
            'communications' => ['state' => 'included'],
            'association_life' => ['state' => 'included'],
        ], ['member_area' => [], 'einvoice' => ['yearly' => 50], 'communications' => [], 'association_life' => []]),
    ],

    'addons' => [
        ['code' => 'operators_extra', 'name' => 'Operatore aggiuntivo', 'kind' => 'operators', 'visible' => true, 'grants' => ['operators' => 1], 'prices' => $flat(300)],
        ['code' => 'storage_extra', 'name' => 'Spazio file aggiuntivo (10 GB)', 'kind' => 'storage', 'visible' => true, 'grants' => ['storage_gb' => 10], 'prices' => $flat(300)],
        // Area soci: 5 € fissi fino a 50 soci, poi 0,06 · 0,04 · 0,02 € a socio al mese.
        [
            'code' => 'member_area',
            'name' => 'Area soci e pagamenti',
            'kind' => 'package',
            'visible' => false,
            'grants' => ['package' => 'member_area', 'limits' => []],
            'prices' => $memberTiers(500, [['up_to' => 150, 'unit_cents' => 6], ['up_to' => 400, 'unit_cents' => 4], ['up_to' => null, 'unit_cents' => 2]]),
        ],
        $package('site', 'Sito', 1900),
        $package('einvoice', "Fattura elettronica (50 fatture all'anno)", 700, ['yearly' => 50]),
        array_replace($package('communications', 'Comunicazioni', 900), ['visible' => false]),
        array_replace($package('advanced_accounting', 'Contabilità avanzata', 1900), ['visible' => false]),
        array_replace($package('association_life', 'Vita associativa', 700), ['visible' => false]),
        $package('sport', 'Sport', 1200),
        // Versione Web; la Interna costa il 50% (da aggiungere come addon a parte quando serve venderla).
        $resources('resources_hourly_web', 'Risorse orarie, Web', 'hourly', 2, [
            ['up_to' => 2, 'amount_cents' => 1900], ['up_to' => 5, 'amount_cents' => 2900], ['up_to' => 10, 'amount_cents' => 4500], ['up_to' => null, 'amount_cents' => 6900],
        ]),
        $resources('resources_daily_web', 'Risorse giornaliere, Web', 'daily', 10, [
            ['up_to' => 10, 'amount_cents' => 1900], ['up_to' => 25, 'amount_cents' => 2900], ['up_to' => 50, 'amount_cents' => 4500], ['up_to' => null, 'amount_cents' => 6900],
        ]),
        // Oltre 400 risorse stagionali: su preventivo, quindi nessuna fascia aperta.
        $resources('resources_seasonal_web', 'Risorse stagionali, Web', 'seasonal', 50, [
            ['up_to' => 50, 'amount_cents' => 1900], ['up_to' => 150, 'amount_cents' => 3500], ['up_to' => 400, 'amount_cents' => 5900],
        ]),
        ['code' => 'custom_domain', 'name' => 'Dominio personalizzato', 'kind' => 'domain', 'visible' => true, 'grants' => [], 'prices' => $flat(300)],
        ['code' => 'dedicated_db', 'name' => 'Database dedicato', 'kind' => 'dedicated_db', 'visible' => true, 'grants' => [], 'prices' => $flat(2500)],
    ],

    'promos' => [],
];

// Il piano a scaglioni resta nelle versioni già vendute; dalla versione 2 non è più in vendita.
$catalog['plans'][] = array_replace($catalog['plans'][0], ['plan_version' => 2, 'visible' => false]);

return $catalog;
