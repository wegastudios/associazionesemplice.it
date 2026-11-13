<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Catalogo\CatalogValidator;

/** @return array<string, mixed> */
function seedCatalog(): array
{
    /** @var array<string, mixed> */
    return require dirname(__DIR__, 3).'/config/catalogo.php';
}

it('accetta il catalogo iniziale e lo completa con schema e prodotto', function (): void {
    $doc = CatalogValidator::validate(seedCatalog());

    expect($doc['schema'])->toBe('catalogo/v1')
        ->and($doc['product'])->toBe('saas')
        ->and($doc['rev'])->toBe(2)
        ->and(array_column($doc['plans'], 'code'))->toBe(['gestionale', 'piccola', 'media', 'grande']);
});

it('rifiuta campi sconosciuti, importi non interi e testo con HTML', function (array $change): void {
    $catalog = seedCatalog();
    $catalog = $change['apply']($catalog);

    expect(fn () => CatalogValidator::validate($catalog))->toThrow(InvalidArgumentException::class);
})->with([
    'campo sconosciuto' => [['apply' => fn (array $c): array => $c + ['extra' => 1]]],
    'prezzo con virgola' => [['apply' => function (array $c): array {
        $c['addons'][0]['prices'][0]['amount_cents'] = 3.5;

        return $c;
    }]],
    'prezzo negativo' => [['apply' => function (array $c): array {
        $c['addons'][0]['prices'][0]['amount_cents'] = -1;

        return $c;
    }]],
    'html nel nome' => [['apply' => function (array $c): array {
        $c['plans'][0]['name'] = '<b>Gestionale</b>';

        return $c;
    }]],
    'livello di supporto vecchio' => [['apply' => function (array $c): array {
        $c['plans'][0]['support_level'] = 'standard';

        return $c;
    }]],
    'piano senza caratteristiche' => [['apply' => function (array $c): array {
        $c['plans'][0]['features'] = [];

        return $c;
    }]],
    'nessun piano visibile' => [['apply' => function (array $c): array {
        foreach (array_keys($c['plans']) as $i) {
            $c['plans'][$i]['visible'] = false;
        }

        return $c;
    }]],
    'compare con maiuscole' => [['apply' => function (array $c): array {
        $c['features'][0]['compare'] = 'Members';

        return $c;
    }]],
    'compare duplicato' => [['apply' => function (array $c): array {
        $c['features'][1]['compare'] = $c['features'][0]['compare'];

        return $c;
    }]],
    'fasce che non crescono' => [['apply' => function (array $c): array {
        $c['plans'][0]['prices'][0]['tiers'][1]['up_to'] = 100;

        return $c;
    }]],
    'fascia aperta non in fondo' => [['apply' => function (array $c): array {
        $c['plans'][0]['prices'][0]['tiers'][0]['up_to'] = null;

        return $c;
    }]],
    'piano duplicato' => [['apply' => function (array $c): array {
        $c['plans'][] = $c['plans'][0];

        return $c;
    }]],
    'promo con date incoerenti' => [['apply' => function (array $c): array {
        $c['promos'][] = ['code' => 'PRIMAVERA', 'name' => 'Primavera', 'discount' => ['type' => 'percent', 'value' => 20], 'plans' => null,
            'valid_from' => '2026-12-31', 'valid_to' => '2026-01-01', 'max_uses' => null, 'stackable' => false];

        return $c;
    }]],
    'promo su piano che non esiste' => [['apply' => function (array $c): array {
        $c['promos'][] = ['code' => 'PRIMAVERA', 'name' => 'Primavera', 'discount' => ['type' => 'percent', 'value' => 20], 'plans' => ['non_esiste'],
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'max_uses' => null, 'stackable' => false];

        return $c;
    }]],
]);

it('accetta una promo valida', function (): void {
    $catalog = seedCatalog();
    $catalog['promos'][] = ['code' => 'PRIMAVERA', 'name' => 'Primavera', 'discount' => ['type' => 'percent', 'value' => 20], 'plans' => ['gestionale'],
        'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'max_uses' => 50, 'stackable' => false];

    expect(CatalogValidator::validate($catalog)['promos'])->toHaveCount(1);
});

it('ha tre piani a prezzo fisso per fasce di soci, con i pacchetti inclusi e il piano a scaglioni non più in vendita', function (): void {
    $doc = CatalogValidator::validate(seedCatalog());
    $plans = collect($doc['plans'])->keyBy(fn (array $p): string => $p['code'].'@'.$p['plan_version']);

    $expected = ['piccola' => [100, 1900, 2], 'media' => [300, 3500, 5], 'grande' => [1000, 5900, 50]];
    foreach ($expected as $code => [$members, $monthly, $operators]) {
        $plan = $plans["{$code}@1"];
        expect($plan['visible'])->toBeTrue()
            ->and($plan['trial_days'])->toBe(30)
            ->and($plan['limits']['members'])->toBe($members)
            ->and($plan['limits']['operators'])->toBe($operators)
            ->and($plan['prices'][0]['amount_cents'])->toBe($monthly)
            ->and($plan['prices'][1]['amount_cents'])->toBe($monthly * 10);
    }

    expect($plans['piccola@1']['limits']['packages'])->toBe([])
        ->and(array_keys($plans['media@1']['limits']['packages']))->toBe(['member_area', 'einvoice'])
        ->and($plans['media@1']['limits']['packages']['einvoice'])->toBe(['yearly' => 50])
        ->and(array_keys($plans['grande@1']['limits']['packages']))->toBe(['member_area', 'einvoice', 'communications', 'association_life'])
        ->and($plans['gestionale@1']['visible'])->toBeFalse();

    $addons = collect($doc['addons'])->keyBy('code');
    foreach (['member_area', 'communications', 'association_life', 'advanced_accounting'] as $included) {
        expect($addons[$included]['visible'])->toBeFalse();
    }
    expect($addons['einvoice']['visible'])->toBeTrue();
});

it('ha condizioni di partenza neutre: IVA standard, nessuna commissione, prova senza esportazioni né commercialista', function (): void {
    $doc = CatalogValidator::validate(seedCatalog());

    expect($doc['terms']['vat']['mode'])->toBe('standard')
        ->and($doc['terms']['vat']['note'])->toBe('')
        ->and($doc['terms']['payments'])->toBe(['own_gateway_percent' => 0, 'collected_by_us_percent' => 0])
        ->and($doc['terms']['trial'])->toBe(['exports' => false, 'accountant_access' => false, 'end_discount_percent' => 0, 'purge_days' => 7])
        ->and($doc['terms']['members'])->toBe(['warn_percent' => 80, 'full_percent' => 100, 'tolerance_percent' => 10, 'tolerance_days' => 15, 'guests_free_percent' => 10]);

    // Un catalogo senza condizioni (le revisioni precedenti) vale come i valori di partenza, con IVA.
    $old = seedCatalog();
    unset($old['terms']);
    expect(CatalogValidator::validate($old)['terms']['vat'])->toBe(['mode' => 'standard', 'note' => '']);

    $bad = seedCatalog();
    $bad['terms']['vat'] = ['mode' => 'exempt'];
    expect(fn () => CatalogValidator::validate($bad))->toThrow(InvalidArgumentException::class);
    $bad = seedCatalog();
    $bad['terms']['payments']['collected_by_us_percent'] = 31;
    expect(fn () => CatalogValidator::validate($bad))->toThrow(InvalidArgumentException::class);
});

it('tiene pronti lo sconto di fine prova e i pacchetti di soci, nascosti finché non in vendita', function (): void {
    $doc = CatalogValidator::validate(seedCatalog());
    $addons = collect($doc['addons'])->keyBy('code');

    expect($doc['terms']['trial']['end_discount_percent'])->toBe(0)
        ->and($doc['terms']['trial']['purge_days'])->toBe(7)
        ->and($addons['members_500']['visible'])->toBeFalse()
        ->and($addons['members_500']['prices'][0]['amount_cents'])->toBe(1700)
        ->and($addons['members_500']['prices'][1]['amount_cents'])->toBe(17000)
        ->and($addons['members_1000']['visible'])->toBeFalse()
        ->and($addons['members_1000']['prices'][0]['amount_cents'])->toBe(3000)
        ->and($addons['members_1000']['prices'][1]['amount_cents'])->toBe(30000)
        ->and($addons['members_500']['grants'])->toBe(['members' => 500]);

    $bad = seedCatalog();
    $bad['terms']['trial']['end_discount_percent'] = 101;
    expect(fn () => CatalogValidator::validate($bad))->toThrow(InvalidArgumentException::class);
    $bad = seedCatalog();
    $bad['terms']['trial']['purge_days'] = 0;
    expect(fn () => CatalogValidator::validate($bad))->toThrow(InvalidArgumentException::class);
});

it('controlla il limite di soci: avvisi in ordine, tolleranza e quota ospiti entro i confini', function (): void {
    foreach ([['warn_percent' => 90, 'full_percent' => 80], ['tolerance_percent' => 51], ['tolerance_days' => 91], ['guests_free_percent' => 101], ['warn_percent' => 0]] as $wrong) {
        $bad = seedCatalog();
        $bad['terms']['members'] = $wrong + $bad['terms']['members'];
        expect(fn () => CatalogValidator::validate($bad))->toThrow(InvalidArgumentException::class);
    }
});
