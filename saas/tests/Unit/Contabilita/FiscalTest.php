<?php

declare(strict_types=1);

use App\Modules\Contabilita\Rules\Fiscal;

// Casi portati dal FiscalTest del plugin.
it('riconosce le partite IVA valide', function (string $v): void {
    expect(Fiscal::isValidVat($v))->toBeTrue();
})->with(['12345678903', 'IT12345678903', 'it 12345678903', '12345 678903']);

it('rifiuta le partite IVA non valide', function (string $v): void {
    expect(Fiscal::isValidVat($v))->toBeFalse();
})->with(['', '12345678901', '1234567890', '123456789031', '00000000000', 'ABCDEFGHIJK']);

it('normalizza la partita IVA e controlla il codice fiscale dell\'ente', function (): void {
    expect(Fiscal::normalizeVat(' IT 123-456.78903 '))->toBe('12345678903')
        ->and(Fiscal::isValidEntityTaxCode('12345678903'))->toBeTrue()
        ->and(Fiscal::isValidEntityTaxCode('12345678901'))->toBeFalse()
        ->and(Fiscal::isValidEntityTaxCode('vrdgpp75b10f205b'))->toBeTrue()
        ->and(Fiscal::isValidEntityTaxCode('VRDGPP75B10F205C'))->toBeFalse()
        ->and(Fiscal::isValidEntityTaxCode(''))->toBeFalse();
});
