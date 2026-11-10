<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Catalogo\CatalogValidator;
use App\Modules\Piattaforma\Catalogo\PriceCalculator;

/** @return array<string, mixed> */
function priceCases(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/catalogo-casi.json'), true, 32, JSON_THROW_ON_ERROR);
}

it('calcola i casi comuni con il portale', function (): void {
    $all = priceCases();

    foreach (['members_tiers', 'members_tiers_year'] as $group) {
        /** @var array{price: array<string, mixed>, cases: list<array{members: int, cents: int}>} $set */
        $set = $all[$group];
        foreach ($set['cases'] as $case) {
            expect(PriceCalculator::members($set['price'], $case['members']))->toBe($case['cents'], "{$group} {$case['members']}");
        }
    }
    foreach (['step_tiers', 'step_tiers_closed'] as $group) {
        /** @var array{price: array<string, mixed>, cases: list<array{quantity: int, cents: int|null}>} $set */
        $set = $all[$group];
        foreach ($set['cases'] as $case) {
            expect(PriceCalculator::steps($set['price'], $case['quantity']))->toBe($case['cents'], "{$group} {$case['quantity']}");
        }
    }
    /** @var list<array{net: int, percent: int, vat: int}> $vat */
    $vat = $all['vat'];
    foreach ($vat as $case) {
        expect(PriceCalculator::vat($case['net'], $case['percent']))->toBe($case['vat'], "iva {$case['net']}");
    }
});

it('dà gli stessi importi del listino di PERIMETRO.md per il catalogo iniziale', function (): void {
    $catalog = CatalogValidator::validate(require dirname(__DIR__, 3).'/config/catalogo.php');
    $month = $catalog['plans'][0]['prices'][0];

    expect(PriceCalculator::members($month, 150))->toBe(3300)->and(PriceCalculator::members($month, 1000))->toBe(9900);
});
