<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Export;

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Import\LedgerExport;
use App\Modules\Contabilita\Ledger\StatementSnapshots;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\DataExport;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/** Prima nota completa (anche gli stornati), conti, voci, esercizi, ricevute, dati dell'ente. */
final readonly class ContabilitaDataExport implements DataExport
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private LedgerExport $ledger,
        private StatementSnapshots $snapshots,
    ) {}

    public function files(Operator $operator): array
    {
        if (! $operator->can(Ability::ViewAccounting)) {
            return [];
        }
        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $money = static fn (mixed $v): string => is_numeric($v) ? Money::plain((int) $v) : '';
        $files = ['contabilita/prima-nota.csv' => $this->ledger->csv(null, true)['csv']];

        $rows = [];
        foreach ($this->db()->table('app.accounts as a')->leftJoin('app.account_balances as b', 'b.account_id', '=', 'a.id')->orderBy('a.sort_order')->get(['a.name', 'a.type', 'a.opening_cents', 'b.balance_cents', 'a.closed_at']) as $a) {
            $rows[] = [$s($a->name ?? ''), $s($a->type ?? ''), $money($a->opening_cents ?? null), $money($a->balance_cents ?? null), ($a->closed_at ?? null) !== null ? 'sì' : ''];
        }
        $files['contabilita/conti.csv'] = Csv::build(['Conto', 'Tipo', 'Saldo di partenza', 'Saldo attuale', 'Chiuso'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.categories')->orderBy('sort_order')->get(['name', 'kind', 'fiscal_group', 'deleted_at']) as $c) {
            $rows[] = [$s($c->name ?? ''), CategoryKind::tryFrom($s($c->kind ?? ''))?->label() ?? $s($c->kind ?? ''), $s($c->fiscal_group ?? ''), ($c->deleted_at ?? null) !== null ? 'sì' : ''];
        }
        $files['contabilita/voci.csv'] = Csv::build(['Voce', 'Natura', 'Rendiconto', 'Tolta'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.fiscal_years')->orderBy('year')->get(['year', 'starts_on', 'ends_on', 'status']) as $y) {
            $rows[] = [$s($y->year ?? ''), $s($y->starts_on ?? ''), $s($y->ends_on ?? ''), ($y->status ?? '') === 'closed' ? 'chiuso' : 'aperto'];
        }
        $files['contabilita/esercizi.csv'] = Csv::build(['Esercizio', 'Dal', 'Al', 'Stato'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.receipts as r')->leftJoin('app.people as p', 'p.id', '=', 'r.payer_person_id')->orderBy('r.year')->orderBy('r.number')
            ->get(['r.number', 'r.year', 'r.issued_on', 'p.last_name', 'p.first_name', 'r.total_cents', 'r.voided_at', 'r.void_reason']) as $r) {
            $rows[] = [$s($r->number ?? '').'/'.$s($r->year ?? ''), $s($r->issued_on ?? ''), trim($s($r->last_name ?? '').' '.$s($r->first_name ?? '')), $money($r->total_cents ?? null),
                ($r->voided_at ?? null) !== null ? 'annullata: '.$s($r->void_reason ?? '') : ''];
        }
        $files['contabilita/ricevute.csv'] = Csv::build(['Numero', 'Data', 'Ricevuto da', 'Totale', 'Annullata'], $rows);

        $rows = [];
        foreach ($this->db()->table('app.settings')->where('key', 'like', 'ente.%')->orderBy('key')->get(['key', 'value']) as $e) {
            $v = json_decode($s($e->value ?? 'null'), true);
            $rows[] = [substr($s($e->key ?? ''), 5), is_scalar($v) ? (string) $v : ''];
        }
        $files['contabilita/dati-ente.csv'] = Csv::build(['Dato', 'Valore'], $rows);

        // Il rendiconto di ogni esercizio chiuso com'era alla chiusura, tutte le versioni (anche quelle superate da una riapertura).
        $files['contabilita/rendiconti-esercizi-chiusi.json'] = json_encode($this->snapshots->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        return $files;
    }

    private function db(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
