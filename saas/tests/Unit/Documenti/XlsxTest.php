<?php

declare(strict_types=1);

use App\Modules\Documenti\Contracts\Xlsx;

// Casi portati dall'XlsxTest del plugin.
function xlsxFiles(string $sheetXml, array $extra = []): array
{
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';

    return array_merge([
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .'<sheet name="Soci" sheetId="1" r:id="rId1"/><sheet name="Nascosto" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="x" Target="/xl/worksheets/sheet2.xml"/></Relationships>',
        'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst '.$ns.'><si><t>Nome</t></si><si><t>Cognome</t></si><si><r><t>Ros</t></r><r><t>si</t></r></si><si><t>Mario</t></si></sst>',
        'xl/styles.xml' => '<?xml version="1.0"?><styleSheet '.$ns.'><numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00 &quot;€&quot;"/></numFmts>'
            .'<cellXfs count="4"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet '.$ns.'><sheetData>'.$sheetXml.'</sheetData></worksheet>',
        'xl/worksheets/sheet2.xml' => '<?xml version="1.0"?><worksheet '.$ns.'><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>segreto</t></is></c></row></sheetData></worksheet>',
    ], $extra);
}

it('legge testi, numeri e date e tiene i numeri di riga', function (): void {
    $rows = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Data</t></is></c><c r="D1" t="inlineStr"><is><t>Importo</t></is></c></row>'
        .'<row r="3"><c r="A3" t="s"><v>3</v></c><c r="B3" t="s"><v>2</v></c><c r="C3" s="1"><v>45306</v></c><c r="D3" s="3"><v>12.5</v></c></row>'
        .'<row r="4"><c r="A4"/></row>'
        .'<row r="6"><c r="B6" t="str"><f>A1</f><v>formula</v></c><c r="D6" t="b"><v>1</v></c><c r="E6" s="2"><v>45306.75</v></c></row>';
    $sheets = Xlsx::parse(xlsxFiles($rows));
    expect($sheets)->toHaveCount(1)
        ->and($sheets[0]['name'])->toBe('Soci')
        ->and($sheets[0]['lines'])->toBe([1, 3, 6])
        ->and($sheets[0]['rows'][0])->toBe(['Nome', 'Cognome', 'Data', 'Importo'])
        ->and($sheets[0]['rows'][1])->toBe(['Mario', 'Rossi', '2024-01-15', '12.5'])
        ->and($sheets[0]['rows'][2])->toBe(['', 'formula', '', '1', '2024-01-15']);
});

it('converte le date seriali', function (): void {
    expect(Xlsx::serialToDate(45306))->toBe('2024-01-15')
        ->and(Xlsx::serialToDate(1))->toBe('1900-01-01')
        ->and(Xlsx::serialToDate(61))->toBe('1900-03-01')
        ->and(Xlsx::serialToDate(45306.99))->toBe('2024-01-15')
        ->and(Xlsx::serialToDate(43844, true))->toBe('2024-01-15')
        ->and(Xlsx::serialToDate(0))->toBeNull();
});

it('riconosce i formati data e le colonne', function (): void {
    expect(Xlsx::isDateFormat('dd/mm/yyyy'))->toBeTrue()
        ->and(Xlsx::isDateFormat('[$-410]d mmmm yyyy;@'))->toBeTrue()
        ->and(Xlsx::isDateFormat('#,##0.00'))->toBeFalse()
        ->and(Xlsx::isDateFormat('0.00 "€"'))->toBeFalse()
        ->and(Xlsx::isDateFormat('General'))->toBeFalse()
        ->and(Xlsx::columnIndex('A1'))->toBe(0)->and(Xlsx::columnIndex('Z9'))->toBe(25)
        ->and(Xlsx::columnIndex('AA2'))->toBe(26)->and(Xlsx::columnIndex('BC12'))->toBe(54);
});

it('rifiuta file non validi e con entità', function (): void {
    expect(fn () => Xlsx::parse(['foo' => 'bar']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Xlsx::parse(xlsxFiles('', ['xl/sharedStrings.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&a;</t></si></sst>'])))
        ->toThrow(InvalidArgumentException::class);
});

it('legge numeri in notazione scientifica e namespace con prefisso', function (): void {
    $ns = 'xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $rows = '<x:row r="1"><x:c r="A1"><x:v>1.5E+3</x:v></x:c><x:c r="B1"><x:v>0.1</x:v></x:c></x:row>';
    $s = Xlsx::parse(xlsxFiles('', ['xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><x:worksheet '.$ns.'><x:sheetData>'.$rows.'</x:sheetData></x:worksheet>']));
    expect($s[0]['rows'])->toBe([['1500', '0.1']]);
});
