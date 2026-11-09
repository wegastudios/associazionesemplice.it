<?php

declare(strict_types=1);

use App\Modules\Documenti\Contracts\Csv;

it('neutralizza le formule, anche dopo spazi o caratteri invisibili e nella forma a larghezza intera', function (string $in): void {
    expect(Csv::cell($in))->toBe("'".$in);
})->with([
    '=SOMMA(A1:A2)', '@cmd', '+cmd|x', '-cmd', "\t=1+1", '  =1+1', "\u{200B}=1+1", "\u{FEFF}@x", '＝1+1', ' ＝1+1', '＠x', "\n=1+1",
]);

it('lascia stare testi e numeri normali', function (string $in): void {
    expect(Csv::cell($in))->toBe($in);
})->with(['Anna Rossi', '-12,50', '+39 333 1234567', '  -5', '12=3', '', 'a=b', 'è un test']);

it('i numeri interi e il nulla passano', function (): void {
    expect(Csv::cell(42))->toBe('42')->and(Csv::cell(null))->toBe('');
});
