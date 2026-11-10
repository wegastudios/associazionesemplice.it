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
        ->and($doc['rev'])->toBe(1)
        ->and(array_column($doc['plans'], 'code'))->toBe(['gestionale']);
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
    'piano senza caratteristiche' => [['apply' => function (array $c): array {
        $c['plans'][0]['features'] = [];

        return $c;
    }]],
    'nessun piano visibile' => [['apply' => function (array $c): array {
        $c['plans'][0]['visible'] = false;

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
