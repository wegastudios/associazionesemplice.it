<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Export;

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Import\LedgerExport;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Ledger\StatementSnapshots;
use App\Modules\Contabilita\Receipts\ReceiptFileProblem;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\ReadableExport;
use App\Modules\Core\Contracts\ZipName;
use ArrayObject;
use Illuminate\Database\DatabaseManager;

/**
 * Per ogni anno fiscale: rendiconto in PDF (per gli anni chiusi, dalla fotografia alla chiusura), prima nota in CSV e
 * le ricevute in PDF (i file fissati all'emissione). Le ricevute che non si riesce a leggere si elencano in un file.
 */
final readonly class ContabilitaReadableExport implements ReadableExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private FiscalYearService $years,
        private StatementSnapshots $snapshots,
        private StatementPdf $statementPdf,
        private LedgerExport $ledger,
        private ReceiptFiles $receipts,
    ) {}

    public function files(Operator $operator, bool $withCertificates): array
    {
        if (! $operator->can(Ability::ViewAccounting)) {
            return [];
        }
        $files = [];
        foreach ($this->years->all() as $y) {
            $year = $y['year'];
            $dir = 'Anno fiscale '.$year.'/';
            $files[$dir.'Rendiconto.pdf'] = fn (): string => $this->statementPdf->render($this->snapshots->forYear($year)['statement'], $operator->orgName);
            $files[$dir.'Prima nota.csv'] = fn (): string => $this->ledger->csv($year, true)['csv'];

            /** @var ArrayObject<int, string> $problems */
            $problems = new ArrayObject;
            $rows = $this->db->connection($this->context->require()->connection)->table('app.receipts as r')->leftJoin('app.people as p', 'p.id', '=', 'r.payer_person_id')
                ->where('r.year', $year)->whereNull('r.voided_at')->orderBy('r.number')->get(['r.id', 'r.number', 'p.last_name', 'p.first_name']);
            foreach ($rows as $r) {
                $id = is_string($r->id ?? null) ? $r->id : '';
                $number = (is_scalar($r->number ?? null) ? (string) $r->number : '').'-'.$year;
                $who = trim((is_string($r->last_name ?? null) ? $r->last_name : '').' '.(is_string($r->first_name ?? null) ? $r->first_name : ''));
                $name = ZipName::clean('Ricevuta '.$number.($who !== '' ? ' - '.$who : '')).'.pdf';
                $files[$dir.'Ricevute/'.$name] = function () use ($id, $number, $problems): ?string {
                    try {
                        return $this->receipts->document($id)['pdf'];
                    } catch (ReceiptFileProblem|LedgerError $e) {
                        $problems->append('Ricevuta n. '.$number.': '.$e->getMessage());

                        return null;
                    }
                };
            }
            // Ultimo file dell'anno: elenca le ricevute che non è stato possibile mettere nella copia.
            $files[$dir.'Ricevute/Ricevute non incluse.txt'] = static fn (): ?string => count($problems) === 0 ? null : implode("\r\n", [
                'Queste ricevute non sono nella copia perché il loro PDF non è disponibile o non risulta uguale a quando è stato emesso.',
                'Un amministratore può controllarle da «Verifica delle ricevute».',
                '',
                ...$problems->getArrayCopy(),
                '',
            ]);
        }

        return $files;
    }
}
