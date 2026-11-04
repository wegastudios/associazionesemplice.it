<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/**
 * Prima nota dell'associazione corrente. Ogni operazione è una transazione: o va tutta o non va niente.
 * Gli errori per l'operatore sono LedgerError.
 */
interface Ledger
{
    /** @return array{receipt_id: string|null, receipt: string|null, transactions: list<string>} */
    public function recordIncome(Income $income): array;

    /** @return string id del movimento */
    public function recordExpense(Expense $expense): string;

    /** @return string identificativo del giroconto */
    public function recordTransfer(string $date, string $fromAccountId, string $toAccountId, int $cents, string $description = ''): string;

    /** Storna il movimento e il suo gruppo (giroconto, ricevuta). @return int righe stornate */
    public function void(string $transactionId, string $reason): int;

    /** @return array<string, int> id del conto => saldo in centesimi */
    public function balances(): array;
}
