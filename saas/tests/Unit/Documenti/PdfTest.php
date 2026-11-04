<?php

declare(strict_types=1);

use App\Modules\Documenti\Contracts\Pdf;

// Casi portati dal PdfTest del plugin.
function pdfOffsetsAreConsistent(string $out): void
{
    preg_match('/startxref\n(\d+)\n/', $out, $m);
    expect(substr($out, (int) $m[1], 4))->toBe('xref');
    preg_match_all('/^(\d{10}) 00000 n $/m', $out, $offs);
    foreach ($offs[1] as $i => $off) {
        expect(substr($out, (int) $off, strlen(($i + 1).' 0 obj')))->toBe(($i + 1).' 0 obj');
    }
}

it('scrive un documento con struttura e indirizzi coerenti', function (): void {
    $pdf = new Pdf;
    $pdf->text(50, 60, 'Ricevuta', 14, true);
    $pdf->line(50, 70, 500, 70);
    $pdf->addPage();
    $pdf->text(50, 60, 'Seconda pagina');
    $out = $pdf->output();
    expect($out)->toStartWith('%PDF-1.4')->toEndWith("%%EOF\n")->toContain('/Count 2')->toContain('/MediaBox [0 0 595 842]');
    pdfOffsetsAreConsistent($out);
    expect((new Pdf(landscape: true))->output())->toContain('/MediaBox [0 0 842 595]');
});

it('codifica il testo in WinAnsi e protegge i caratteri speciali', function (): void {
    $s = Pdf::encode('Perché (50 €) a\\b');
    expect($s)->toContain("Perch\xE9")->toContain("\x80")->toContain('\\(50')->toContain('\\)')->toContain('a\\\\b');
});

it('misura, spezza e taglia il testo', function (): void {
    expect(Pdf::textWidth('12345', 10))->toEqualWithDelta(5 * 0.556 * 10, 0.001)
        ->and(Pdf::textWidth('Totale', 12, true))->toBeGreaterThan(Pdf::textWidth('Totale', 12));
    $lines = Pdf::wrap('Quota associativa 2026 per Mario Rossi e famiglia numerosa', 120, 10);
    expect(count($lines))->toBeGreaterThan(1);
    foreach ($lines as $l) {
        expect(Pdf::textWidth($l, 10))->toBeLessThanOrEqual(125);
    }
    expect(Pdf::wrap('', 100))->toBe([''])
        ->and(Pdf::textWidth(Pdf::fit('Un nome molto molto lungo da tagliare', 60, 10), 10))->toBeLessThanOrEqual(60);
});

it('usa il punto come separatore decimale e cambia colore', function (): void {
    $pdf = new Pdf;
    $pdf->text(50.5, 60.25, 'x');
    $pdf->color('#ff0000');
    $pdf->text(60, 80, 'Rosso');
    $pdf->color('');
    $pdf->text(60, 100, 'Nero');
    $out = $pdf->output();
    expect($out)->toMatch('/ 50\.50 \d+\.\d{2} Td/')->toContain('1.000 0.000 0.000 rg')->toContain('0 g /F1');
    pdfOffsetsAreConsistent($out);
});
