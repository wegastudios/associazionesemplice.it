<?php

declare(strict_types=1);

use App\Modules\Contabilita\Import\LedgerSheet as L;

// Casi portati dal LedgerImportTest del plugin. Dati di prova inventati.
it('legge gli importi', function (): void {
    expect(L::parseAmount('1.234,56'))->toBe(123456)->and(L::parseAmount('1,234.56'))->toBe(123456)
        ->and(L::parseAmount('12,5'))->toBe(1250)->and(L::parseAmount('1234.5'))->toBe(123450)
        ->and(L::parseAmount('(12,50)'))->toBe(-1250)->and(L::parseAmount('12,50-'))->toBe(-1250)->and(L::parseAmount('-12,50'))->toBe(-1250)
        ->and(L::parseAmount('€ 10'))->toBe(1000)->and(L::parseAmount("10,00\xC2\xA0€"))->toBe(1000)->and(L::parseAmount('0'))->toBe(0)
        ->and(L::parseAmount('abc'))->toBeNull()->and(L::parseAmount(''))->toBeNull();
});

it('legge date e mesi', function (): void {
    expect(L::parseDate('2024-01-15'))->toBe('2024-01-15')->and(L::parseDate('15/01/2024'))->toBe('2024-01-15')
        ->and(L::parseDate('5-1-2024'))->toBe('2024-01-05')->and(L::parseDate('15.01.24'))->toBe('2024-01-15')
        ->and(L::parseDate('2024-01-15 10:30:00'))->toBe('2024-01-15')->and(L::parseDate('31/02/2024'))->toBeNull()
        ->and(L::parseDate('2024-13-01'))->toBeNull()->and(L::parseDate('ieri'))->toBeNull()
        ->and(L::parseMonth('2024-03'))->toBe('2024-03')->and(L::parseMonth('03/2024'))->toBe('2024-03')
        ->and(L::parseMonth('Marzo 2024'))->toBe('2024-03')->and(L::parseMonth('2024-03-15'))->toBe('2024-03')->and(L::parseMonth('boh'))->toBeNull();
});

it('riconosce tipi, modalità e conti', function (): void {
    expect(L::typeFromText('entrata'))->toBe('income')->and(L::typeFromText('incasso'))->toBe('income')
        ->and(L::typeFromText('uscita'))->toBe('expense')->and(L::typeFromText('spesa'))->toBe('expense')
        ->and(L::typeFromText('girocontoinentrata'))->toBe('transfer_in')->and(L::typeFromText('girocontoinuscita'))->toBe('transfer_out')
        ->and(L::typeFromText('boh'))->toBeNull()
        ->and(L::methodFromText('contanti'))->toBe('cash')->and(L::methodFromText('bonifico'))->toBe('bank_transfer')
        ->and(L::methodFromText('poscarta'))->toBe('pos')->and(L::methodFromText('cartaonlinestripe'))->toBe('stripe')
        ->and(L::methodFromText('assegno'))->toBe('check')->and(L::methodFromText('piccioneviaggiatore'))->toBeNull()
        ->and(L::guessAccountType('cassacontanti'))->toBe('cash')->and(L::guessAccountType('bancaetica'))->toBe('bank')->and(L::guessAccountType('contopos'))->toBe('pos');
});

it('legge le righe sotto un titolo con i numeri di riga veri', function (): void {
    $r = L::parseTable([
        ['Rendiconto 2023'], ['Data', 'Entrata', 'Uscita', 'Descrizione'], ['15/01/2024', '10,00', '', 'Quota'],
        ['16/01/2024', '', '45,50', 'Affitto'], ['boh', '1', '', 'data sbagliata'], ['17/01/2024', '1,0', '2,0', 'entrambi'],
    ], [1, 2, 5, 6, 7, 8])['rows'] ?? [];
    expect($r)->toHaveCount(4)->and(array_column($r, 'line'))->toBe([5, 6, 7, 8])
        ->and($r[0])->toMatchArray(['type' => 'income', 'cents' => 1000])->and($r[1])->toMatchArray(['type' => 'expense', 'cents' => 4550])
        ->and($r[2]['errors'])->not->toBe([])->and($r[3]['errors'])->not->toBe([]);
});

it('legge una colonna sola di importi, con segno o con il tipo', function (): void {
    $r = L::parseTable([['Data', 'Importo', 'Tipo'], ['2024-01-01', '-20,00', ''], ['2024-01-02', '30,00', ''], ['2024-01-03', '15,00', 'Uscita'], ['2024-01-04', '', '']])['rows'] ?? [];
    expect(array_slice(array_column($r, 'type'), 0, 3))->toBe(['expense', 'income', 'expense'])
        ->and(array_slice(array_column($r, 'cents'), 0, 3))->toBe([2000, 3000, 1500])->and($r[3]['errors'])->not->toBe([]);
});

it('riconosce l\'intestazione', function (): void {
    expect(L::acceptsHeader(['data', 'importo']))->toBeTrue()
        ->and(L::acceptsHeader(['data', 'tipo', 'conto', 'modalita', 'voce', 'attivita', 'ntessera', 'persona', 'descrizione', 'competenza', 'riferimento', 'entrata', 'uscita']))->toBeTrue()
        ->and(L::acceptsHeader(['nome', 'cognome', 'email']))->toBeFalse()->and(L::acceptsHeader(['data', 'descrizione']))->toBeFalse();
});

function ledgerCtx(array $over = []): array
{
    return array_merge([
        'accounts' => [['id' => 'a1', 'name' => 'Cassa contanti', 'type' => 'cash'], ['id' => 'a2', 'name' => 'Conto corrente', 'type' => 'bank']],
        'categories' => [
            ['id' => 'c10', 'name' => 'Quota associativa', 'kind' => 'membership'], ['id' => 'c11', 'name' => 'Quota attività / corso', 'kind' => 'activity_fee'],
            ['id' => 'c12', 'name' => 'Altra entrata', 'kind' => 'other_income'], ['id' => 'c13', 'name' => 'Costo generale', 'kind' => 'general_cost'],
            ['id' => 'c14', 'name' => 'Rettifica', 'kind' => 'adjustment'],
        ],
        'people' => [['id' => 'p7', 'card' => '5', 'first' => 'Mario', 'last' => 'Rossi'], ['id' => 'p8', 'card' => '6', 'first' => 'Anna', 'last' => 'Rossi']],
        'activities' => [['id' => 't3', 'name' => 'Yoga']],
        'existing' => [],
        'default_account_id' => null,
    ], $over);
}

function ledgerPlan(array $rows, array $ctx = []): array
{
    $h = ['Data', 'Tipo', 'Conto', 'Modalità', 'Voce', 'Importo', 'Descrizione', 'Riferimento', 'N. tessera', 'Persona', 'Attività', 'Competenza'];

    return L::plan(L::parseTable(array_merge([$h], $rows))['rows'] ?? [], ledgerCtx($ctx));
}

it('trova conto, voce, persona e attività', function (): void {
    $p = ledgerPlan([
        ['15/01/2024', 'Entrata', 'cassa CONTANTI', 'Contanti', 'quota associativa', '10,00', 'Quota', '', '5', '', '', ''],
        ['16/01/2024', 'Entrata', 'Conto corrente', '', 'Corso di yoga', '30', '', '', '', 'Rossi Anna', 'yoga', 'gennaio 2024'],
    ]);
    expect($p[0]['action'])->toBe('create')->and($p[0]['data'])->toMatchArray(['account_id' => 'a1', 'category_id' => 'c10', 'person_id' => 'p7', 'method' => 'cash'])
        ->and($p[1]['data'])->toMatchArray(['account_id' => 'a2', 'category_id' => 'c11', 'person_id' => 'p8', 'activity_id' => 't3', 'month' => '2024-01', 'method' => 'bank_transfer']);
});

it('ripiega su voci generiche, propone conti nuovi e segnala gli errori', function (): void {
    $p = ledgerPlan([
        ['16/01/2024', 'Uscita', 'Banca Etica', '', 'Pulizie', '45,50', 'Sala', 'FT-1', '', '', '', ''],
        ['17/01/2024', 'Uscita', '', '', '', '5', '', '', '', '', '', ''],
        ['18/01/2024', 'Entrata', 'Cassa contanti', '', '', '5', '', '', '99', 'Sconosciuto', 'Danza', 'forse'],
    ]);
    expect($p[0]['action'])->toBe('create')->and($p[0]['data'])->toMatchArray(['new_account' => 'Banca Etica', 'new_account_type' => 'bank', 'category_id' => 'c13', 'description' => '[Pulizie] Sala'])
        ->and($p[0]['warnings'])->not->toBe([])
        ->and($p[1]['action'])->toBe('error')
        ->and($p[2]['action'])->toBe('create')->and($p[2]['data']['category_id'] ?? null)->toBe('c12')->and($p[2]['warnings'])->toHaveCount(5);
    $d = ledgerPlan([['17/01/2024', 'Uscita', '', '', '', '5', '', '', '', '', '', '']], ['default_account_id' => 'a1']);
    expect($d[0]['data'])->toMatchArray(['account_id' => 'a1', 'method' => 'cash']);
});

it('conta i doppioni invece di saltarli tutti', function (): void {
    $key = L::key('2024-01-15', 'a1', 'income', 1000, 'Quota', '');
    $row = ['15/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', 'Quota', '', '', '', '', ''];
    expect(array_column(ledgerPlan([$row, $row, $row], ['existing' => [$key => 2]]), 'action'))->toBe(['duplicate', 'duplicate', 'create']);
});

it('accoppia i giroconti', function (): void {
    $p = ledgerPlan([
        ['20/01/2024', 'Giroconto in uscita', 'Cassa contanti', 'Contanti', 'Giroconto', '100', 'Versamento', '', '', '', '', ''],
        ['20/01/2024', 'Giroconto in entrata', 'Conto corrente', 'Contanti', 'Giroconto', '100', 'Versamento', '', '', '', '', ''],
        ['21/01/2024', 'Giroconto in uscita', 'Cassa contanti', 'Contanti', 'Giroconto', '50', '', '', '', '', '', ''],
    ]);
    expect(array_column($p, 'action'))->toBe(['transfer', 'paired', 'skip'])
        ->and($p[0]['data']['from']['id'] ?? null)->toBe('a1')->and($p[0]['data']['to']['id'] ?? null)->toBe('a2')->and($p[0]['data']['cents'] ?? null)->toBe(10000);
    $s = L::summary($p, ['a1' => 'Cassa contanti', 'a2' => 'Conto corrente']);
    expect($s['per_account'])->toBe(['Cassa contanti' => -10000, 'Conto corrente' => 10000])->and($s['counts']['transfer'])->toBe(1)->and($s['counts']['skip'])->toBe(1);
});

it('riassume totali e periodo', function (): void {
    $s = L::summary(ledgerPlan([
        ['15/01/2024', 'Entrata', 'Cassa contanti', '', 'Quota associativa', '10,00', '', '', '', '', '', ''],
        ['16/02/2024', 'Uscita', 'Banca Etica', '', 'Pulizie', '4,50', '', '', '', '', '', ''],
    ]), ['a1' => 'Cassa contanti']);
    expect($s)->toMatchArray(['income' => 1000, 'expense' => 450, 'from' => '2024-01-15', 'to' => '2024-02-16', 'new_accounts' => ['Banca Etica']])
        ->and($s['per_account']['Banca Etica'])->toBe(-450);
});
