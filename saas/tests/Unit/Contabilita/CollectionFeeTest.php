<?php

declare(strict_types=1);

use App\Modules\Contabilita\Rules\CollectionFee as F;

it('calcola la commissione: fisso più percentuale, arrotondata al centesimo', function (): void {
    expect(F::cents(10000, 25, 150))->toBe(25 + 150)      // 0,25 € + 1,5% di 100 €
        ->and(F::cents(1999, 0, 100))->toBe(20)           // 1% di 19,99 € = 0,1999 → 0,20
        ->and(F::cents(1000, 0, 0))->toBe(0)
        ->and(F::cents(0, 100, 100))->toBe(0)
        ->and(F::cents(-5, 100, 100))->toBe(0)
        ->and(F::cents(500, 30, 0))->toBe(30);
});

it('i limiti sono 20 euro e 25 per cento', function (): void {
    expect(F::validate(2000, 2500))->toBe([])
        ->and(F::validate(2001, 0))->toHaveCount(1)
        ->and(F::validate(0, 2501))->toHaveCount(1)
        ->and(F::validate(-1, -1))->toHaveCount(2);
});
