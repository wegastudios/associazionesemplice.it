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
        ->and(array_column($doc['plans'], 'code'))->toBe(['gestionale', 'piccola', 'media', 'grande', 'gestionale']);
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
        foreach ($c['plans'] as $i => $plan) {
            $c['plans'][$i]['visible'] = false;
        }
        $c['plans'][0]['visible'] = false;

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

    $expected = ['piccola' => [100, 1900, 2], 'media' => [300, 3500, 5], 'grande' => [1000, 5900, 1000]];
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
        ->and($plans['gestionale@2']['visible'])->toBeFalse()
        ->and($plans['gestionale@1']['visible'])->toBeTrue();

    $addons = collect($doc['addons'])->keyBy('code');
    foreach (['member_area', 'communications', 'association_life', 'advanced_accounting'] as $included) {
        expect($addons[$included]['visible'])->toBeFalse();
    }
    expect($addons['einvoice']['visible'])->toBeTrue();
});
