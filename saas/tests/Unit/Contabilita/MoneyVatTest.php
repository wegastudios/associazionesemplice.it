<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Rules\Vat;

// Casi dal plugin (FiscalTest) e dal suo Money: le stesse regole nei due progetti.

it('formatta gli importi in euro', function (): void {
    expect(Money::format(1234567))->toBe('12.345,67 €')
        ->and(Money::format(-50))->toBe('-0,50 €')
        ->and(Money::format(0))->toBe('0,00 €')
        ->and(Money::plain(123450))->toBe('1234,50')
        ->and(Money::plain(-5))->toBe('-0,05');
});

it('legge gli importi scritti come li scrive una persona', function (string $input, ?int $cents): void {
    expect(Money::parse($input))->toBe($cents);
})->with([
    ['12,50', 1250], ['12.50', 1250], ['1.500', 150000], ['1.500,00', 150000], ['€ 12', 1200], ['0,29', 29],
    ['0,295', 30], ['-3,10', -310], ['1 000,00', 100000], ['', null], ['abc', null], ['12,5,0', null],
    ['1000000000', 100000000000], ['1000000001', null],
]);

it('riconosce le partite IVA valide', function (): void {
    foreach (['12345678903', 'IT12345678903', 'it 12345678903', '12345 678903'] as $v) {
        expect(Vat::isValidNumber($v))->toBeTrue($v);
    }
    foreach (['', '12345678901', '1234567890', '123456789031', '00000000000', 'ABCDEFGHIJK'] as $v) {
        expect(Vat::isValidNumber($v))->toBeFalse($v);
    }
    expect(Vat::normalizeNumber(' IT 123-456.78903 '))->toBe('12345678903');
});

it('scompone l\'IVA senza perdere centesimi', function (): void {
    expect(Vat::split(12200, 22, true))->toBe(['net' => 10000, 'vat' => 2200, 'gross' => 12200])
        ->and(Vat::split(1000, 0, true))->toBe(['net' => 1000, 'vat' => 0, 'gross' => 1000])
        ->and(Vat::split(10000, 22, false))->toBe(['net' => 10000, 'vat' => 2200, 'gross' => 12200])
        ->and(Vat::split(1500, 10, false))->toBe(['net' => 1500, 'vat' => 150, 'gross' => 1650]);

    foreach ([1, 99, 333, 1234, 9999] as $cents) {
        foreach ([22, 10, 5, 4] as $rate) {
            $s = Vat::split($cents, $rate, true);
            expect($s['net'] + $s['vat'])->toBe($cents);
            $t = Vat::split($cents, $rate, false);
            expect($t['net'] + $t['vat'])->toBe($t['gross']);
        }
    }
});

it('distingue fuori campo IVA dallo zero per cento', function (): void {
    expect(Vat::cleanRate(null))->toBeNull()
        ->and(Vat::cleanRate(''))->toBeNull()
        ->and(Vat::cleanRate('none'))->toBeNull()
        ->and(Vat::cleanRate('abc'))->toBeNull()
        ->and(Vat::cleanRate('99'))->toBeNull()
        ->and(Vat::cleanRate('22'))->toBe(22)
        ->and(Vat::cleanRate('0'))->toBe(0);
});
