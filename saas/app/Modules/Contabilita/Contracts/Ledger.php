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

    /**
     * Emette la ricevuta (una sola) per incassi già registrati senza ricevuta (vedi ReceiptHold). Ripetuta non emette una seconda ricevuta.
     *
     * @param  list<string>  $transactionIds
     * @return string|null «numero/anno», o null se non c'era niente da emettere
     */
    public function issueReceipt(array $transactionIds): ?string;

    /** @return string id del movimento */
    public function recordExpense(Expense $expense): string;

    /**
     * Incassi e spese di un import (H11), in blocco: stessi controlli di recordIncome e recordExpense, senza ricevuta né
     * commissione, un solo inserimento per tutte. Una voce rifiutata non ferma le altre. Nel registro non va una riga per
     * movimento: la riga riassuntiva la scrive chi importa. Va chiamato dentro la transazione di chi importa.
     *
     * @param  list<Income|Expense>  $entries
     * @param  string|null  $importId  l'importazione, salvata su ogni movimento (transactions.import_id)
     * @return list<list<string>|LedgerError> per ogni voce, nello stesso ordine: gli id dei movimenti o l'errore
     */
    public function recordImported(array $entries, ?string $importId = null): array;

    /**
     * @param  string|null  $importId  l'importazione che registra il giroconto (transactions.import_id), o null
     * @return string identificativo del giroconto
     */
    public function recordTransfer(string $date, string $fromAccountId, string $toAccountId, int $cents, string $description = '', ?string $importId = null): string;

    /** Storna il movimento e il suo gruppo (giroconto, ricevuta). @return int righe stornate */
    public function void(string $transactionId, string $reason): int;

    /** Cambia il centro di costo (l'attività) di un incasso o di una spesa non stornati; null lo toglie. */
    public function assignActivity(string $transactionId, ?string $activityId): void;

    /**
     * Entrate e uscite non stornate per attività.
     *
     * @param  list<string>  $activityIds
     * @return array<string, array{income: int, expense: int}>
     */
    public function activityTotals(array $activityIds): array;

    /**
     * Entrate e uscite non stornate delle attività, un blocco per ogni anno fiscale in cui cadono i movimenti.
     *
     * @param  list<string>  $activityIds
     * @return list<array{year: int, starts_on: string, ends_on: string, income: int, expense: int}>
     */
    public function activityTotalsByFiscalYear(array $activityIds): array;

    /**
     * Movimenti di un'attività, dal più recente.
     *
     * @return list<array{id: string, date: string, type: string, cents: int, category: string, person_id: string|null, description: string, receipt: string|null, receipt_id: string|null, voided: bool}>
     */
    public function activityMovements(string $activityId): array;

    /**
     * Incassi non stornati di un'attività per persona e mese di competenza (o mese del movimento).
     *
     * @return array<string, array<string, int>> persona => mese «AAAA-MM» => centesimi
     */
    public function activityPayments(string $activityId): array;

    /**
     * Importi dei movimenti indicati che non sono stornati (per sapere quanto è stato pagato davvero).
     *
     * @param  list<string>  $transactionIds
     * @return array<string, int> id del movimento => centesimi
     */
    public function liveAmounts(array $transactionIds): array;

    /** @return list<array{id: string, name: string}> categorie attive per gli incassi (senza la rettifica di cassa) */
    public function incomeCategories(): array;

    /** @return array<string, int> id del conto => saldo in centesimi */
    public function balances(): array;

    /** Prima categoria attiva di quella natura (per esempio la quota associativa), o null. */
    public function categoryFor(CategoryKind $kind): ?string;

    /** @return list<array{id: string, name: string}> conti aperti, nell'ordine scelto dall'associazione */
    public function openAccounts(): array;
}
