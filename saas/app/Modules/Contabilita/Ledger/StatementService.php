<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;

/**
 * Rendiconto per cassa di un esercizio: entrate e uscite per raggruppamento e categoria, saldi dei conti a inizio e a
 * fine esercizio. Gli storni non contano, i giroconti nemmeno (spostano denaro tra conti, non sono entrate né uscite).
 *
 * @phpstan-type Statement array{
 *     year: int, starts_on: string, ends_on: string, status: string,
 *     income: list<array{group: string, category: string, cents: int}>,
 *     expense: list<array{group: string, category: string, cents: int}>,
 *     totals: array{income: int, expense: int, result: int},
 *     accounts: list<array{name: string, opening: int, closing: int}>
 * }
 */
final readonly class StatementService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    /** @return Statement */
    public function forYear(int $year): array
    {
        $c = $this->db->connection($this->context->require()->connection);
        $fy = $c->table('app.fiscal_years')->where('year', $year)->first(['starts_on', 'ends_on', 'status']);
        if ($fy === null || ! is_string($fy->starts_on) || ! is_string($fy->ends_on) || ! is_string($fy->status)) {
            throw new LedgerError("L'anno fiscale {$year} non esiste.");
        }

        $rows = $c->select(<<<'SQL'
            SELECT t.type, coalesce(c.fiscal_group, 'Altro') AS grp, c.name AS category, sum(t.amount_cents) AS cents
            FROM app.transactions t JOIN app.categories c ON c.org_id = t.org_id AND c.id = t.category_id
            WHERE t.fiscal_year = ? AND t.voided_at IS NULL AND t.type IN ('income', 'expense')
            GROUP BY t.type, grp, c.name
            ORDER BY grp, c.name
        SQL, [$year]);

        $income = [];
        $expense = [];
        foreach ($rows as $r) {
            if (! is_object($r)) {
                continue;
            }
            $line = [
                'group' => is_string($r->grp ?? null) ? $r->grp : '',
                'category' => is_string($r->category ?? null) ? $r->category : '',
                'cents' => is_numeric($r->cents ?? null) ? (int) $r->cents : 0,
            ];
            if (($r->type ?? null) === 'income') {
                $income[] = $line;
            } else {
                $expense[] = $line;
            }
        }
        $totalIncome = array_sum(array_column($income, 'cents'));
        $totalExpense = array_sum(array_column($expense, 'cents'));

        // Saldi a ritroso dal saldo di oggi (app.account_balances: saldo iniziale più tutti i movimenti non stornati,
        // tenuto dai trigger): si tolgono i movimenti da inizio anno in poi, senza rileggere quelli degli anni prima.
        // Stesso risultato della somma di tutti i movimenti precedenti. I movimenti si sommano per conto prima di unire i
        // saldi: la riga del saldo cambia a ogni movimento e non va riletta per ogni movimento.
        $accounts = $c->select(<<<'SQL'
            SELECT a.name, b.balance_cents - coalesce(m.since_start, 0) AS opening, b.balance_cents - coalesce(m.after_end, 0) AS closing
            FROM app.accounts a
            JOIN app.account_balances b ON b.org_id = a.org_id AND b.account_id = a.id
            LEFT JOIN (
                SELECT account_id,
                       sum(CASE WHEN type IN ('income', 'transfer_in') THEN amount_cents ELSE -amount_cents END) AS since_start,
                       sum(CASE WHEN type IN ('income', 'transfer_in') THEN amount_cents ELSE -amount_cents END) FILTER (WHERE tx_date > ?) AS after_end
                FROM app.transactions
                WHERE voided_at IS NULL AND tx_date >= ?
                GROUP BY account_id
            ) m ON m.account_id = a.id
            ORDER BY a.sort_order, a.name
        SQL, [$fy->ends_on, $fy->starts_on]);

        return [
            'year' => $year,
            'starts_on' => $fy->starts_on,
            'ends_on' => $fy->ends_on,
            'status' => $fy->status,
            'income' => $income,
            'expense' => $expense,
            'totals' => ['income' => $totalIncome, 'expense' => $totalExpense, 'result' => $totalIncome - $totalExpense],
            'accounts' => array_values(array_map(static fn (object $a): array => [
                'name' => is_string($a->name ?? null) ? $a->name : '',
                'opening' => is_numeric($a->opening ?? null) ? (int) $a->opening : 0,
                'closing' => is_numeric($a->closing ?? null) ? (int) $a->closing : 0,
            ], array_filter($accounts, is_object(...)))),
        ];
    }
}
