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
        'cash_report' => ['state' => 'included'],
        'activities' => ['state' => 'included'],
        'operators' => ['state' => 'limit', 'text' => $operatorsText],
        'storage' => ['state' => 'limit', 'text' => '5 MB per ogni socio (minimo 250 MB)'],
        'resources' => ['state' => 'excluded'],
        'site' => ['state' => 'excluded'],
        'advanced_accounting' => ['state' => 'excluded'],
        'fiscal' => ['state' => 'limit', 'text' => 'In preparazione'],
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
    'description' => 'Il gestionale online per associazioni, circoli e società sportive: soci e tessere, entrate e uscite (prima nota), rendiconto per cassa, corsi ed eventi. Si usa dal browser, senza installare niente.',
    'rev' => 2,
    'published_at' => '2026-11-12',
    'currency' => 'EUR',

    'features' => [
        ['id' => 'members', 'text' => 'Soci, tessere e libro soci', 'group' => 'Base', 'compare' => 'members'],
        ['id' => 'ledger', 'text' => 'Entrate e uscite (prima nota) e ricevute', 'group' => 'Base', 'compare' => 'ledger'],
        ['id' => 'cash_report', 'text' => 'Rendiconto per cassa (schema Mod. D)', 'group' => 'Base'],
        ['id' => 'activities', 'text' => 'Corsi, eventi e presenze', 'group' => 'Base', 'compare' => 'activities'],
        ['id' => 'operators', 'text' => 'Persone che lavorano nel gestionale (operatori)', 'group' => 'Base', 'compare' => 'operators'],
        ['id' => 'storage', 'text' => 'Spazio per documenti e allegati', 'group' => 'Base'],
        ['id' => 'member_area', 'text' => 'Area riservata dei soci e pagamenti online', 'group' => 'Pacchetti', 'compare' => 'member_area'],
        ['id' => 'resources', 'text' => 'Risorse e prenotazioni', 'group' => 'Pacchetti', 'compare' => 'resources'],
        ['id' => 'site', 'text' => "Sito dell'associazione", 'group' => 'Pacchetti', 'compare' => 'site'],
        ['id' => 'einvoice', 'text' => 'Fattura elettronica', 'group' => 'Pacchetti', 'compare' => 'einvoice'],
        ['id' => 'communications', 'text' => 'Email e SMS ai soci', 'group' => 'Pacchetti'],
        ['id' => 'advanced_accounting', 'text' => 'Contabilità avanzata', 'group' => 'Pacchetti'],
        ['id' => 'fiscal', 'text' => 'Adempimenti fiscali: IVA e corrispettivi, regime L. 398/1991, 5 per mille', 'group' => 'Pacchetti', 'compare' => 'fiscal'],
        ['id' => 'association_life', 'text' => 'Assemblee, verbali e votazioni', 'group' => 'Pacchetti'],
        ['id' => 'sport', 'text' => 'Sport: certificati medici e affiliazioni', 'group' => 'Pacchetti'],
    ],

    'plans' => [
        [
            'code' => 'gestionale',
            'plan_version' => 1,
            'name' => 'Gestionale',
            'audience' => 'Associazioni, APS, ODV e società sportive',
            'order' => 1,
            'visible' => false,
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
        $tier('piccola', 1, 'Piccola', 'Per le associazioni piccole, fino a 100 soci', 100, 1900, 2, '2 persone', [
            'member_area' => ['state' => 'excluded'],
            'einvoice' => ['state' => 'excluded'],
            'communications' => ['state' => 'excluded'],
            'association_life' => ['state' => 'excluded'],
        ], []),
        $tier('media', 2, 'Media', 'Per le associazioni medie, fino a 300 soci', 300, 3500, 5, '5 persone', [
            'member_area' => ['state' => 'included'],
            'einvoice' => ['state' => 'limit', 'text' => "50 fatture all'anno incluse"],
            'communications' => ['state' => 'excluded'],
            'association_life' => ['state' => 'excluded'],
        ], ['member_area' => [], 'einvoice' => ['yearly' => 50]]),
        $tier('grande', 3, 'Grande', 'Per le associazioni grandi, fino a 1.000 soci', 1000, 5900, 1000, 'Quante ne servono', [
            'member_area' => ['state' => 'included'],
            'einvoice' => ['state' => 'limit', 'text' => "50 fatture all'anno incluse"],
            'communications' => ['state' => 'included'],
            'association_life' => ['state' => 'included'],
        ], ['member_area' => [], 'einvoice' => ['yearly' => 50], 'communications' => [], 'association_life' => []]),
    ],

    'addons' => [
        ['code' => 'operators_extra', 'name' => 'Una persona in più nel gestionale', 'kind' => 'operators', 'visible' => true, 'grants' => ['operators' => 1], 'prices' => $flat(300)],
        ['code' => 'storage_extra', 'name' => '10 GB di spazio in più', 'kind' => 'storage', 'visible' => true, 'grants' => ['storage_gb' => 10], 'prices' => $flat(300)],
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
        array_replace($package('communications', 'Email e SMS ai soci', 900), ['visible' => false]),
        array_replace($package('advanced_accounting', 'Contabilità avanzata', 1900), ['visible' => false]),
        array_replace($package('association_life', 'Assemblee, verbali e votazioni', 700), ['visible' => false]),
        $package('sport', 'Sport: certificati medici e affiliazioni', 1200),
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
        ['code' => 'custom_domain', 'name' => 'Il tuo indirizzo web (dominio personalizzato)', 'kind' => 'domain', 'visible' => true, 'grants' => [], 'prices' => $flat(300)],
        ['code' => 'dedicated_db', 'name' => 'Archivio separato solo per la tua associazione (database dedicato)', 'kind' => 'dedicated_db', 'visible' => true, 'grants' => [], 'prices' => $flat(2500)],
        // Pacchetti di soci solo per la Grande, oltre i 1.000 (decisione della titolare, 13/11/2026): 3,4 € a socio, 17 € per 500 e 30 € per 1.000.
        // Nascosti finché lei non li mette in vendita dal pannello. Piccola e Media non li hanno: chi supera i limiti passa al piano sopra.
        ['code' => 'members_500', 'name' => '500 soci in più (solo con il piano Grande)', 'kind' => 'members', 'visible' => false, 'grants' => ['members' => 500], 'prices' => $flat(1700)],
        ['code' => 'members_1000', 'name' => '1.000 soci in più (solo con il piano Grande)', 'kind' => 'members', 'visible' => false, 'grants' => ['members' => 1000], 'prices' => $flat(3000)],
    ],

    'promos' => [],

    // Valori di partenza neutri. Regime IVA, frase da mostrare e commissione si impostano dal pannello, scheda Condizioni: non scriverli qui.
    'terms' => [
        'vat' => ['mode' => 'standard', 'note' => ''],
        'payments' => ['own_gateway_percent' => 0, 'collected_by_us_percent' => 0],
        'trial' => ['exports' => false, 'accountant_access' => false, 'end_discount_percent' => 0, 'purge_days' => 7],
        // Limite di soci del piano (decisione 13/11/2026): avvisi all'80% e al 100%, tolleranza del 10% per 15 giorni, poi blocco dei nuovi soci. Quota franca ospiti: proposta, da confermare.
        'members' => ['warn_percent' => 80, 'full_percent' => 100, 'tolerance_percent' => 10, 'tolerance_days' => 15, 'guests_free_percent' => 20],
    ],
];

// Il piano a scaglioni resta per le versioni già vendute; non è più in vendita (il nuovo listino è dei tre piani sopra).

return $catalog;
