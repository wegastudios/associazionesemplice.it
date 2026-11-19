<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Export;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Documenti\Contracts\Pdf;
use DateTimeImmutable;

/**
 * Rendiconto per cassa di un anno fiscale in PDF, dai dati del rendiconto (per un anno chiuso, la fotografia alla
 * chiusura): entrate e uscite per raggruppamento e categoria, risultato e saldi dei conti.
 *
 * @phpstan-import-type Statement from \App\Modules\Contabilita\Ledger\StatementService
 */
final class StatementPdf
{
    /** @param  Statement  $s */
    public function render(array $s, string $orgName): string
    {
        $pdf = new Pdf;
        $w = $pdf->width;
        $y = 60.0;
        $pdf->text(50, $y, $orgName, 15, true);
        $y += 20;
        $pdf->text(50, $y, 'Rendiconto per cassa: anno fiscale '.$s['year'], 12, true);
        $y += 15;
        $pdf->text(50, $y, 'Dal '.self::date($s['starts_on']).' al '.self::date($s['ends_on']).($s['status'] === 'closed' ? ' · anno chiuso' : ' · anno aperto'), 10);
        $pdf->line(50, $y + 8, $w - 50, $y + 8, 1.0);
        $y += 30;

        foreach ([['Entrate', $s['income'], $s['totals']['income']], ['Uscite', $s['expense'], $s['totals']['expense']]] as [$title, $lines, $total]) {
            $y = $this->section($pdf, $y, $title, $lines, $total);
        }
        $y = $this->need($pdf, $y, 40);
        $pdf->rect(50, $y - 12, $w - 100, 22);
        $pdf->text(56, $y + 3, 'Risultato dell\'anno (entrate meno uscite)', 11, true);
        $pdf->text($w - 56, $y + 3, Money::format($s['totals']['result']), 11, true, 'R');
        $y += 36;

        $y = $this->need($pdf, $y, 60);
        $pdf->text(50, $y, 'Conti', 12, true);
        $y += 18;
        $pdf->text(56, $y, 'Conto', 9, true);
        $pdf->text($w - 160, $y, 'Saldo iniziale', 9, true, 'R');
        $pdf->text($w - 56, $y, 'Saldo finale', 9, true, 'R');
        $y += 14;
        foreach ($s['accounts'] as $a) {
            $y = $this->need($pdf, $y, 20);
            $pdf->text(56, $y, Pdf::fit($a['name'], $w - 260, 10), 10);
            $pdf->text($w - 160, $y, Money::format($a['opening']), 10, false, 'R');
            $pdf->text($w - 56, $y, Money::format($a['closing']), 10, false, 'R');
            $y += 15;
        }

        return $pdf->output();
    }

    /** @param  list<array{group: string, category: string, cents: int}>  $lines */
    private function section(Pdf $pdf, float $y, string $title, array $lines, int $total): float
    {
        $w = $pdf->width;
        $y = $this->need($pdf, $y, 50);
        $pdf->text(50, $y, $title, 12, true);
        $y += 18;
        $group = null;
        foreach ($lines as $l) {
            if ($l['group'] !== $group) {
                $group = $l['group'];
                $y = $this->need($pdf, $y, 34);
                $pdf->text(56, $y, $group, 10, true);
                $y += 15;
            }
            $y = $this->need($pdf, $y, 20);
            $pdf->text(70, $y, Pdf::fit($l['category'], $w - 230, 10), 10);
            $pdf->text($w - 56, $y, Money::format($l['cents']), 10, false, 'R');
            $y += 14;
        }
        if ($lines === []) {
            $pdf->text(70, $y, 'Nessun movimento.', 10);
            $y += 14;
        }
        $pdf->line(50, $y - 4, $w - 50, $y - 4, 0.5);
        $y += 10;
        $pdf->text(56, $y, 'Totale '.mb_strtolower($title), 10, true);
        $pdf->text($w - 56, $y, Money::format($total), 10, true, 'R');

        return $y + 28;
    }

    /** Se non c'è più posto in fondo alla pagina, ne comincia un'altra. */
    private function need(Pdf $pdf, float $y, float $space): float
    {
        if ($y + $space > $pdf->height - 50) {
            $pdf->addPage();

            return 60.0;
        }

        return $y;
    }

    private static function date(string $d): string
    {
        return (new DateTimeImmutable($d))->format('d/m/Y');
    }
}
