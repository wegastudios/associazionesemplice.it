<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Receipts;

use App\Modules\Attivita\Contracts\Activities;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Rules\ReceiptRules;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Documenti\Contracts\Pdf;
use App\Modules\Soci\Contracts\People;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Ricevuta in PDF (dal Receipts::build del plugin): intestazione dell'ente, chi ha pagato (per un minore il genitore),
 * le voci, il totale con l'eventuale IVA, la modalità e, dai 5 ai 18 anni, la nota sulla detrazione.
 *
 * Due tempi: snapshot() raccoglie tutti i dati che finiscono sul foglio (si salvano cifrati all'emissione), render()
 * produce il PDF solo da quei dati. Così la ricevuta si rifà uguale anche se poi cambiano il socio o l'ente.
 *
 * @phpstan-import-type Card from People
 */
final readonly class ReceiptPdf
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgSettings $settings,
        private People $people,
        private Activities $activities,
    ) {}

    /**
     * @return array{pdf: string, filename: string, number: string, email: string|null, holder: string}
     */
    public function build(string $receiptId): array
    {
        $snapshot = $this->snapshot($receiptId);

        return [
            'pdf' => $this->render($snapshot),
            'filename' => self::str($snapshot['filename'] ?? ''),
            'number' => self::str($snapshot['number'] ?? ''),
            'email' => $this->holderEmail($snapshot),
            'holder' => $this->holderName($snapshot),
        ];
    }

    /**
     * Tutti i dati del foglio, in forma semplice (testo, numeri, elenchi): si salvano come JSON cifrato.
     *
     * @return array<string, mixed>
     *
     * @throws LedgerError
     */
    public function snapshot(string $receiptId): array
    {
        $receipt = Str::isUuid($receiptId) ? $this->connection()->table('app.receipts')->where('id', $receiptId)->first() : null;
        if ($receipt === null) {
            throw new LedgerError('Ricevuta non trovata.');
        }
        if (($receipt->voided_at ?? null) !== null) {
            throw new LedgerError('La ricevuta è annullata: l\'incasso è stato stornato.');
        }
        $rows = $this->connection()->table('app.transactions as t')->join('app.categories as c', 'c.id', '=', 't.category_id')
            ->where('t.receipt_id', $receiptId)->whereNull('t.voided_at')->orderBy('t.created_at')->orderBy('t.id')
            ->get(['t.amount_cents', 't.vat_rate', 't.vat_cents', 't.description', 't.method', 't.document_ref', 't.person_id', 't.activity_id', 'c.name as category', 'c.kind']);
        $first = $rows->first();
        if ($first === null) {
            throw new LedgerError('Ricevuta senza voci.');
        }

        $number = self::str($receipt->number ?? '').'/'.self::str($receipt->year ?? '');
        $date = self::str($receipt->issued_on ?? '');
        $method = self::str($first->method ?? 'other');
        $methodLabel = PaymentMethod::tryFrom($method)?->label() ?? $method;
        $kinds = array_values(array_unique(array_map(static fn (object $r): string => is_string($r->kind ?? null) ? $r->kind : '', $rows->all())));

        $payerId = is_string($receipt->payer_person_id ?? null) ? $receipt->payer_person_id : null;
        $payer = $payerId === null ? null : $this->people->card($payerId);
        $wardId = is_string($first->person_id ?? null) ? $first->person_id : $payerId;
        $ward = $wardId === null ? null : ($wardId === $payerId ? $payer : $this->people->card($wardId));
        $minor = ReceiptRules::minorBlock($payer, $ward, $wardId === null ? [] : $this->people->guardians($wardId), $date, $method, $methodLabel, $kinds);

        $activityNames = $this->activities->names(array_values(array_filter(array_map(static fn (object $r): ?string => is_string($r->activity_id ?? null) ? $r->activity_id : null, $rows->all()))));
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'amount' => is_numeric($r->amount_cents ?? null) ? (int) $r->amount_cents : 0,
                'vat_rate' => is_numeric($r->vat_rate ?? null) ? (int) $r->vat_rate : null,
                'vat' => is_numeric($r->vat_cents ?? null) ? (int) $r->vat_cents : 0,
                'what' => trim(self::str($r->description ?? '')) !== '' ? trim(self::str($r->description)) : self::str($r->category ?? ''),
                'activity' => is_string($r->activity_id ?? null) ? ($activityNames[$r->activity_id] ?? null) : null,
            ];
        }

        $s = fn (string $k): string => is_string($v = $this->settings->get('ente.'.$k)) ? $v : '';
        $name = $this->connection()->table('app.orgs')->value('name');
        $holder = $minor['holder'];
        $who = $holder === null ? 'ricevuta' : (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($holder['last_name'])));

        return [
            'org' => [
                'name' => is_string($name) && $name !== '' ? $name : 'Associazione',
                'tax_code' => $s('tax_code'), 'vat' => $s('vat'), 'zip' => $s('zip'), 'city' => $s('city'), 'province' => $s('province'), 'address' => $s('address'),
            ],
            'number' => $number,
            'filename' => 'ricevuta-'.self::str($receipt->number ?? '').'-'.self::str($receipt->year ?? '').'-'.$who.'.pdf',
            'date' => $date,
            'method_label' => $methodLabel,
            'document_ref' => self::str($first->document_ref ?? ''),
            'kinds' => $kinds,
            'ward' => $ward,
            'minor' => $minor,
            'lines' => $lines,
        ];
    }

    /**
     * Il PDF, solo dai dati dello snapshot.
     *
     * @param  array<string, mixed>  $d
     */
    public function render(array $d): string
    {
        $org = self::arr($d['org'] ?? null);
        $minor = self::arr($d['minor'] ?? null);
        $holder = is_array($minor['holder'] ?? null) ? self::arr($minor['holder']) : null;
        $ward = is_array($d['ward'] ?? null) ? self::arr($d['ward']) : null;
        $minorLines = array_map(self::str(...), is_array($minor['lines'] ?? null) ? $minor['lines'] : []);
        $kinds = array_map(self::str(...), is_array($d['kinds'] ?? null) ? $d['kinds'] : []);
        $lines = is_array($d['lines'] ?? null) ? $d['lines'] : [];

        $pdf = new Pdf;
        $w = $pdf->width;
        $y = $this->header($pdf, $org);
        $title = array_values($kinds) === ['donation'] ? 'RICEVUTA DI EROGAZIONE LIBERALE' : 'RICEVUTA DI PAGAMENTO';
        $pdf->text(50, $y, $title, 14, true);
        $pdf->text($w - 50, $y, 'N. '.self::str($d['number'] ?? ''), 14, true, 'R');
        $y += 20;
        $pdf->text(50, $y, 'Data: '.(new DateTimeImmutable(self::str($d['date'] ?? '')))->format('d/m/Y'), 11);
        $y += 30;
        $pdf->text(50, $y, 'Ricevuto da', 9);
        $y += 15;
        if ($holder !== null) {
            $pdf->text(50, $y, trim(self::str($holder['first_name'] ?? '').' '.self::str($holder['last_name'] ?? '')), 12, true);
            $y += 15;
            if (self::str($holder['tax_code'] ?? '') !== '') {
                $pdf->text(50, $y, 'Codice fiscale: '.self::str($holder['tax_code']), 10);
                $y += 14;
            }
            foreach ($minorLines as $line) {
                foreach (Pdf::wrap($line, $w - 100, 10) as $l) {
                    $pdf->text(50, $y, $l, 10);
                    $y += 14;
                }
            }
        } else {
            $pdf->text(50, $y, '—', 12);
            $y += 15;
        }

        $y += 14;
        $pdf->rect(50, $y - 12, $w - 100, 20);
        $pdf->text(56, $y + 2, 'Descrizione', 10, true);
        $pdf->text($w - 56, $y + 2, 'Importo', 10, true, 'R');
        $y += 24;
        $total = 0;
        $vatRows = [];
        foreach ($lines as $line) {
            $r = self::arr($line);
            $cents = is_numeric($r['amount'] ?? null) ? (int) $r['amount'] : 0;
            $total += $cents;
            $vatRows[] = ['amount' => $cents, 'vat_rate' => is_numeric($r['vat_rate'] ?? null) ? (int) $r['vat_rate'] : null, 'vat' => is_numeric($r['vat'] ?? null) ? (int) $r['vat'] : 0];
            $what = self::str($r['what'] ?? '');
            $activity = is_string($r['activity'] ?? null) ? $r['activity'] : null;
            if ($activity !== null && ! str_contains($what, $activity)) {
                $what .= ' — '.$activity;
            }
            if ($holder !== null && $ward !== null && self::str($ward['id'] ?? '') !== self::str($holder['id'] ?? '') && $minorLines === []) {
                $what .= ' — per '.trim(self::str($ward['first_name'] ?? '').' '.self::str($ward['last_name'] ?? ''));
            }
            foreach (Pdf::wrap($what, $w - 210, 11) as $i => $l) {
                $pdf->text(56, $y, $l, 11);
                if ($i === 0) {
                    $pdf->text($w - 56, $y, Money::format($cents), 11, false, 'R');
                }
                $y += 15;
            }
            $y += 3;
        }
        $pdf->line(50, $y - 6, $w - 50, $y - 6);
        $y += 8;
        $pdf->text(56, $y, 'Totale', 12, true);
        $pdf->text($w - 56, $y, Money::format($total), 12, true, 'R');
        $y += 18;
        foreach (ReceiptRules::vatBreakdown($vatRows) as $rate => $b) {
            $pdf->text(56, $y, 'di cui imponibile '.$rate.'%: '.Money::format($b['net']).' · IVA: '.Money::format($b['vat']), 10);
            $y += 14;
        }
        // L'aliquota zero si chiama «IVA esente». Senza partita IVA nessuna riga ha aliquota: della ricevuta non compare l'IVA.
        $exempt = array_sum(array_map(static fn (array $v): int => $v['vat_rate'] === 0 ? $v['amount'] : 0, $vatRows));
        if ($exempt > 0) {
            $pdf->text(56, $y, 'di cui IVA esente: '.Money::format($exempt), 10);
            $y += 14;
        }
        $y += 6;
        $pdf->text(56, $y, 'Pagato con: '.self::str($d['method_label'] ?? ''), 10);
        if (self::str($d['document_ref'] ?? '') !== '') {
            $y += 14;
            $pdf->text(56, $y, 'Riferimento: '.self::str($d['document_ref']), 10);
        }
        $note = self::str($minor['note'] ?? '');
        foreach ($note === '' ? [] : Pdf::wrap($note, $w - 112, 10) as $l) {
            $y += 14;
            $pdf->text(56, $y, $l, 10);
        }
        $y += 60;
        $pdf->text($w - 60, $y, 'Per l\'associazione', 10, false, 'R');
        $pdf->line($w - 230, $y + 34, $w - 60, $y + 34);

        return $pdf->output();
    }

    /** @param  array<string, mixed>  $d */
    public function holderEmail(array $d): ?string
    {
        $minor = self::arr($d['minor'] ?? null);
        $holder = is_array($minor['holder'] ?? null) ? self::arr($minor['holder']) : null;
        if ($holder === null || ! is_string($holder['id'] ?? null)) {
            return null;
        }
        // L'email è quella di oggi: serve per spedire, non fa parte del foglio.
        $card = $this->people->card($holder['id']);

        return is_string($card['email'] ?? null) && $card['email'] !== '' ? $card['email'] : null;
    }

    /** @param  array<string, mixed>  $d */
    public function holderName(array $d): string
    {
        $minor = self::arr($d['minor'] ?? null);
        $holder = is_array($minor['holder'] ?? null) ? self::arr($minor['holder']) : null;

        return $holder === null ? '' : trim(self::str($holder['first_name'] ?? '').' '.self::str($holder['last_name'] ?? ''));
    }

    /**
     * Intestazione con i dati dell'ente: nome, codice fiscale e partita IVA, sede.
     *
     * @param  array<string, mixed>  $org
     */
    private function header(Pdf $pdf, array $org): float
    {
        $s = static fn (string $k): string => self::str($org[$k] ?? '');
        $name = $s('name');
        $pdf->text(50, 62, $name !== '' ? $name : 'Associazione', 17, true);
        $ids = array_filter([$s('tax_code') !== '' ? 'Codice fiscale: '.$s('tax_code') : '', $s('vat') !== '' ? 'Partita IVA: '.$s('vat') : '']);
        $place = trim(trim($s('zip').' '.$s('city')).($s('province') !== '' ? ' ('.$s('province').')' : ''));
        $address = implode(', ', array_filter([$s('address'), $place]));
        $y = 80;
        foreach (array_filter([implode(' · ', $ids), $address]) as $line) {
            $pdf->text(50, $y, $line, 10);
            $y += 13;
        }
        $pdf->line(50, $y, $pdf->width - 50, $y, 1.2);

        return $y + 26;
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $v): array
    {
        /** @var array<string, mixed> $out */
        $out = is_array($v) ? $v : [];

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
