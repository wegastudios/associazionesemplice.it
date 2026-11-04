<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Import;

use App\Modules\Contabilita\Contracts\Money;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Documenti\Contracts\Csv;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/** Prima nota in CSV con le colonne che l'import riconosce: si può aprire in Excel e ricaricare altrove. */
final readonly class LedgerExport
{
    public const HEADER = ['Data', 'Tipo', 'Conto', 'Modalità', 'Voce', 'Attività', 'N. tessera', 'Persona', 'Descrizione', 'Competenza', 'Riferimento', 'Entrata', 'Uscita', 'Ricevuta', 'Stornato'];

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    /** @return array{csv: string, rows: int} con $year solo quell'esercizio, altrimenti tutto; gli stornati con $withVoided */
    public function csv(?int $year = null, bool $withVoided = false): array
    {
        $q = $this->db->connection($this->context->require()->connection)->table('app.transactions as t')
            ->join('app.accounts as a', 'a.id', '=', 't.account_id')
            ->join('app.categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('app.people as p', 'p.id', '=', 't.person_id')
            ->leftJoin('app.activities as x', 'x.id', '=', 't.activity_id')
            ->leftJoin('app.receipts as r', 'r.id', '=', 't.receipt_id')
            ->when($year !== null, static fn (Builder $q) => $q->where('t.fiscal_year', $year))
            ->when(! $withVoided, static fn (Builder $q) => $q->whereNull('t.voided_at'))
            ->orderBy('t.tx_date')->orderBy('t.created_at')->orderBy('t.id')
            ->select(['t.tx_date', 't.type', 'a.name as account', 't.method', 'c.name as category', 'x.name as activity', 'p.card_number', 'p.first_name', 'p.last_name',
                't.description', 't.competence_month', 't.document_ref', 't.amount_cents', 'r.number', 'r.year', 't.voided_at']);

        $s = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $rows = [];
        foreach ($q->get() as $t) {
            $cents = is_numeric($t->amount_cents ?? null) ? (int) $t->amount_cents : 0;
            $in = in_array($t->type ?? null, ['income', 'transfer_in'], true);
            $rows[] = [
                (string) date('d/m/Y', (int) strtotime($s($t->tx_date ?? ''))),
                match ($t->type ?? null) {
                    'income' => 'Entrata', 'expense' => 'Uscita', 'transfer_in' => 'Giroconto in entrata', default => 'Giroconto in uscita',
                },
                $s($t->account ?? ''),
                PaymentMethod::tryFrom($s($t->method ?? ''))?->label() ?? '',
                $s($t->category ?? ''),
                $s($t->activity ?? ''),
                $s($t->card_number ?? ''),
                trim($s($t->last_name ?? '').' '.$s($t->first_name ?? '')),
                $s($t->description ?? ''),
                $s($t->competence_month ?? ''),
                $s($t->document_ref ?? ''),
                $in ? Money::plain($cents) : '',
                $in ? '' : Money::plain($cents),
                $s($t->number ?? '') !== '' ? $s($t->number).'/'.$s($t->year ?? '') : '',
                ($t->voided_at ?? null) !== null ? 'sì' : '',
            ];
        }

        return ['csv' => Csv::build(self::HEADER, $rows), 'rows' => count($rows)];
    }
}
