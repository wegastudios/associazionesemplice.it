<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Ledger;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Contabilita\Contracts\AccountType;
use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Expense;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Contracts\TxType;
use App\Modules\Contabilita\Rules\Vat;
use App\Modules\Core\Contracts\OrgContext;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Prima nota in partita singola (dal LedgerService del plugin). Qui si controllano i dati dell'operatore; le regole che
 * non devono mai saltare (esercizio aperto, conto aperto, immutabilità, saldi) le fa rispettare il database.
 */
final readonly class LedgerService implements Ledger
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private FiscalYearService $years,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    public function recordIncome(Income $income): array
    {
        if ($income->lines === []) {
            throw new LedgerError('Aggiungi almeno una voce all\'incasso.');
        }
        $method = $this->method($income->accountId, $income->method);
        $this->assertPerson($income->personId);

        $lines = [];
        foreach ($income->lines as $i => $line) {
            $n = $i + 1;
            $kind = $this->categoryKind($line->categoryId);
            if ($kind === null || ! $kind->isIncome() || $kind === CategoryKind::Adjustment) {
                throw new LedgerError("Voce {$n}: categoria non valida per un incasso.");
            }
            if ($line->amountCents <= 0) {
                throw new LedgerError("Voce {$n}: importo non valido.");
            }
            if ($line->competenceMonth !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $line->competenceMonth) !== 1) {
                throw new LedgerError("Voce {$n}: mese di competenza non valido.");
            }
            if ($line->vatRate !== null && ! Vat::isRate($line->vatRate)) {
                throw new LedgerError("Voce {$n}: aliquota IVA non valida.");
            }
            $lines[] = $line;
        }

        return $this->transaction(function () use ($income, $method, $lines): array {
            $year = $this->years->yearFor($income->date);
            $receiptId = null;
            $receiptLabel = null;

            if ($income->receipt) {
                $number = $this->nextNumber('receipt', $year);
                $receiptId = Str::uuid7()->toString();
                $this->connection()->table('app.receipts')->insert([
                    'org_id' => $this->orgId(),
                    'id' => $receiptId,
                    'year' => $year,
                    'number' => $number,
                    'issued_on' => $income->date,
                    'payer_person_id' => $income->personId,
                    'total_cents' => array_sum(array_map(static fn ($l): int => $l->amountCents, $lines)),
                    'created_by' => $this->userId(),
                ]);
                $receiptLabel = $number.'/'.$year;
            }

            $ids = [];
            foreach ($lines as $line) {
                $ids[] = $this->insert([
                    'tx_date' => $income->date,
                    'fiscal_year' => $year,
                    'type' => TxType::Income->value,
                    'amount_cents' => $line->amountCents,
                    'account_id' => $income->accountId,
                    'method' => $method->value,
                    'category_id' => $line->categoryId,
                    'person_id' => $income->personId,
                    'receipt_id' => $receiptId,
                    'competence_month' => $line->competenceMonth,
                    'description' => self::text($line->description, 255),
                    'document_ref' => $income->documentRef === null ? null : self::text($income->documentRef, 80),
                    'vat_rate' => $line->vatRate,
                    'vat_cents' => $line->vatRate === null ? 0 : Vat::split($line->amountCents, $line->vatRate, true)['vat'],
                ]);
            }

            $this->audit->record('ledger.income', 'app.transactions', $ids[0], [
                'lines' => count($ids),
                'cents' => array_sum(array_map(static fn ($l): int => $l->amountCents, $lines)),
                'receipt' => $receiptLabel,
            ]);

            return ['receipt_id' => $receiptId, 'receipt' => $receiptLabel, 'transactions' => $ids];
        });
    }

    public function recordExpense(Expense $expense): string
    {
        $method = $this->method($expense->accountId, $expense->method);
        $kind = $this->categoryKind($expense->categoryId);
        if ($kind === null || ! $kind->isExpense() || $kind === CategoryKind::Adjustment) {
            throw new LedgerError('Categoria non valida per una spesa.');
        }
        if ($expense->amountCents <= 0) {
            throw new LedgerError('Importo non valido.');
        }
        if ($expense->vatRate !== null && ! Vat::isRate($expense->vatRate)) {
            throw new LedgerError('Aliquota IVA non valida.');
        }
        $this->assertPerson($expense->personId);

        return $this->transaction(function () use ($expense, $method): string {
            $id = $this->insert([
                'tx_date' => $expense->date,
                'fiscal_year' => $this->years->yearFor($expense->date),
                'type' => TxType::Expense->value,
                'amount_cents' => $expense->amountCents,
                'account_id' => $expense->accountId,
                'method' => $method->value,
                'category_id' => $expense->categoryId,
                'person_id' => $expense->personId,
                'description' => self::text($expense->description, 255),
                'document_ref' => $expense->documentRef === null ? null : self::text($expense->documentRef, 80),
                'vat_rate' => $expense->vatRate,
                'vat_cents' => $expense->vatRate === null ? 0 : Vat::split($expense->amountCents, $expense->vatRate, true)['vat'],
            ]);
            $this->audit->record('ledger.expense', 'app.transactions', $id, ['cents' => $expense->amountCents]);

            return $id;
        });
    }

    public function recordTransfer(string $date, string $fromAccountId, string $toAccountId, int $cents, string $description = ''): string
    {
        if ($fromAccountId === $toAccountId) {
            throw new LedgerError('Scegli due conti diversi.');
        }
        if ($cents <= 0) {
            throw new LedgerError('Importo non valido.');
        }
        $out = $this->method($fromAccountId, null);
        $in = $this->method($toAccountId, null);
        $category = $this->connection()->table('app.categories')->where('kind', CategoryKind::Adjustment->value)->whereNull('deleted_at')->value('id');
        if (! is_string($category)) {
            throw new LedgerError('Manca la categoria «Rettifica di cassa», usata per i giroconti.');
        }

        return $this->transaction(function () use ($date, $fromAccountId, $toAccountId, $cents, $description, $out, $in, $category): string {
            $year = $this->years->yearFor($date);
            $transfer = Str::uuid7()->toString();
            foreach ([[TxType::TransferOut, $fromAccountId, $out], [TxType::TransferIn, $toAccountId, $in]] as [$type, $account, $method]) {
                $this->insert([
                    'tx_date' => $date,
                    'fiscal_year' => $year,
                    'type' => $type->value,
                    'amount_cents' => $cents,
                    'account_id' => $account,
                    'method' => $method->value,
                    'category_id' => $category,
                    'transfer_id' => $transfer,
                    'description' => self::text($description, 255),
                ]);
            }
            $this->audit->record('ledger.transfer', 'app.transactions', null, ['cents' => $cents]);

            return $transfer;
        });
    }

    public function void(string $transactionId, string $reason): int
    {
        if (! Str::isUuid($transactionId)) {
            throw new LedgerError('Movimento inesistente.');
        }

        return $this->transaction(function () use ($transactionId, $reason): int {
            $count = $this->connection()->scalar('SELECT app.void_transaction(?, ?, ?)', [$transactionId, $reason, $this->userId()]);
            $this->audit->record('ledger.voided', 'app.transactions', $transactionId, ['rows' => is_numeric($count) ? (int) $count : 0]);

            return is_numeric($count) ? (int) $count : 0;
        });
    }

    public function balances(): array
    {
        $out = [];
        foreach ($this->connection()->table('app.account_balances')->get(['account_id', 'balance_cents']) as $row) {
            if (is_string($row->account_id ?? null) && is_numeric($row->balance_cents ?? null)) {
                $out[$row->account_id] = (int) $row->balance_cents;
            }
        }

        return $out;
    }

    /**
     * Saldo ricalcolato da zero dalla prima nota (per le verifiche: deve coincidere con balances()).
     *
     * @return array<string, int>
     */
    public function recomputedBalances(): array
    {
        $rows = $this->connection()->select(<<<'SQL'
            SELECT a.id, a.opening_cents + coalesce(sum(CASE WHEN t.type IN ('income', 'transfer_in') THEN t.amount_cents ELSE -t.amount_cents END)
                   FILTER (WHERE t.voided_at IS NULL), 0) AS balance
            FROM app.accounts a LEFT JOIN app.transactions t ON t.org_id = a.org_id AND t.account_id = a.id
            GROUP BY a.id, a.opening_cents
        SQL);

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row) && is_string($row->id ?? null) && is_numeric($row->balance ?? null)) {
                $out[$row->id] = (int) $row->balance;
            }
        }

        return $out;
    }

    /** Numero successivo senza buchi: la riga del contatore resta bloccata fino alla fine della transazione. */
    private function nextNumber(string $kind, int $year): int
    {
        $c = $this->connection();
        $c->table('app.counters')->insertOrIgnore(['org_id' => $this->orgId(), 'kind' => $kind, 'year' => $year, 'last_value' => 0]);
        $last = $c->table('app.counters')->where('kind', $kind)->where('year', $year)->lockForUpdate()->value('last_value');
        $next = (is_numeric($last) ? (int) $last : 0) + 1;
        $c->table('app.counters')->where('kind', $kind)->where('year', $year)->update(['last_value' => $next]);

        return $next;
    }

    /** @param  array<string, mixed>  $row */
    private function insert(array $row): string
    {
        $id = Str::uuid7()->toString();
        $this->connection()->table('app.transactions')->insert(['org_id' => $this->orgId(), 'id' => $id, 'created_by' => $this->userId()] + $row);

        return $id;
    }

    private function method(string $accountId, ?PaymentMethod $method): PaymentMethod
    {
        $account = Str::isUuid($accountId)
            ? $this->connection()->table('app.accounts')->where('id', $accountId)->first(['type', 'closed_at'])
            : null;
        if ($account === null) {
            throw new LedgerError('Conto non valido.');
        }
        if (($account->closed_at ?? null) !== null) {
            throw new LedgerError('Il conto è chiuso: riaprilo per registrare movimenti.');
        }

        return $method ?? AccountType::from(is_string($account->type ?? null) ? $account->type : 'other')->defaultMethod();
    }

    private function categoryKind(string $categoryId): ?CategoryKind
    {
        if (! Str::isUuid($categoryId)) {
            return null;
        }
        $kind = $this->connection()->table('app.categories')->where('id', $categoryId)->whereNull('deleted_at')->value('kind');

        return is_string($kind) ? CategoryKind::tryFrom($kind) : null;
    }

    private function assertPerson(?string $personId): void
    {
        if ($personId !== null && (! Str::isUuid($personId)
            || ! $this->connection()->table('app.people')->where('id', $personId)->whereNull('deleted_at')->exists())) {
            throw new LedgerError('Persona non trovata.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        try {
            return $this->connection()->transaction($callback);
        } catch (QueryException $e) {
            // I rifiuti dei trigger (esercizio chiuso, conto chiuso…) arrivano come errori del database: all'operatore
            // si mostra il loro messaggio, nient'altro.
            if (($e->errorInfo[0] ?? null) === 'P0001' && is_string($e->errorInfo[2] ?? null)) {
                throw new LedgerError(trim((string) preg_replace('/^ERROR:\s*|\s*CONTEXT:.*$/s', '', $e->errorInfo[2])), 0, $e);
            }
            throw $e;
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }

    private function orgId(): string
    {
        return $this->context->require()->id;
    }

    private function userId(): ?string
    {
        $id = $this->auth->guard()->id();

        return is_string($id) ? $id : null;
    }

    private static function text(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
