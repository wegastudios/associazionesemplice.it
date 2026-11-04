<?php

declare(strict_types=1);

use App\Modules\Contabilita\Rules\ReceiptRules;

// Casi portati dal ReceiptMinorTest del plugin. Dati di prova inventati.
function kid(array $over = []): array
{
    return $over + ['id' => 'k1', 'first_name' => 'Kid', 'last_name' => 'Minore', 'tax_code' => 'MNRKDI14A01H501X', 'birth_date' => '2014-03-01'];
}

function mum(): array
{
    return ['id' => 'g2', 'first_name' => 'Marta', 'last_name' => 'Madre', 'tax_code' => 'MDRMRT80A41H501Y', 'operative' => true];
}

function dad(): array
{
    return ['id' => 'g3', 'first_name' => 'Pino', 'last_name' => 'Padre', 'tax_code' => 'PDRPNI78A01H501W', 'operative' => false];
}

it('lascia la ricevuta di un maggiorenne com\'è', function (): void {
    $payer = ['id' => 'p9', 'first_name' => 'A', 'last_name' => 'B'];
    $b = ReceiptRules::minorBlock($payer, kid(['birth_date' => '1990-01-01']), [mum()], '2026-10-29', 'cash', 'Contanti', ['activity_fee']);
    expect($b['holder'])->toBe($payer)->and($b['note'])->toBe('');
});

it('intesta la ricevuta del minore al genitore operativo con i codici fiscali', function (): void {
    $b = ReceiptRules::minorBlock(kid(), kid(), [dad(), mum()], '2026-10-29', 'bank_transfer', 'Bonifico', ['activity_fee']);
    expect($b['holder']['id'] ?? null)->toBe('g2')
        ->and(implode(' ', $b['lines']))->toContain('PDRPNI78A01H501W')->toContain('MNRKDI14A01H501X')->toContain('01/03/2014');
});

it('dà la ricevuta all\'altro genitore se ha pagato lui', function (): void {
    $b = ReceiptRules::minorBlock(dad(), kid(), [mum(), dad()], '2026-10-29', 'pos', 'POS / carta', ['activity_fee']);
    expect($b['holder']['id'] ?? null)->toBe('g3')->and(implode(' ', $b['lines']))->toContain('MDRMRT80A41H501Y');
});

it('indica se il pagamento è tracciato tra 5 e 18 anni', function (): void {
    expect(ReceiptRules::minorBlock(kid(), kid(), [mum()], '2026-10-29', 'bank_transfer', 'Bonifico', ['activity_fee'])['note'])->toContain('Pagamento tracciato')
        ->and(ReceiptRules::minorBlock(kid(), kid(), [mum()], '2026-10-29', 'cash', 'Contanti', ['activity_fee'])['note'])->toContain('non tracciato')
        ->and(ReceiptRules::minorBlock(kid(['birth_date' => '2024-01-01']), kid(['birth_date' => '2024-01-01']), [mum()], '2026-10-29', 'cash', 'Contanti', ['activity_fee'])['note'])->toBe('')
        ->and(ReceiptRules::minorBlock(kid(), kid(), [mum()], '2026-10-29', 'cash', 'Contanti', ['donation'])['note'])->toBe('');
});

it('lascia com\'è il minore senza tutori', function (): void {
    $b = ReceiptRules::minorBlock(kid(), kid(), [], '2026-10-29', 'cash', 'Contanti', ['activity_fee']);
    expect($b['holder']['id'] ?? null)->toBe('k1')->and($b['lines'])->toBe([]);
});

it('somma imponibile e IVA per aliquota', function (): void {
    expect(ReceiptRules::vatBreakdown([
        ['amount' => 1220, 'vat_rate' => 22, 'vat' => 220],
        ['amount' => 1000, 'vat_rate' => null, 'vat' => 0],
        ['amount' => 610, 'vat_rate' => 22, 'vat' => 110],
        ['amount' => 1100, 'vat_rate' => 10, 'vat' => 100],
    ]))->toBe([10 => ['net' => 1000, 'vat' => 100], 22 => ['net' => 1500, 'vat' => 330]]);
});
