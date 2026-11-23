<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Associazione corrente. È l'unico punto che imposta `app.org_id` sulla connessione: la Row-Level Security
 * mostra e accetta solo le righe di questa associazione.
 */
interface OrgContext
{
    /** Imposta l'associazione sulla sua connessione. Va chiamato all'inizio di ogni richiesta e lavoro in coda. */
    public function enter(string $orgId): CurrentOrg;

    /** Azzera `app.org_id` su ogni connessione su cui è stato impostato; quelle appena aperte sono già vuote. */
    public function leave(): void;

    public function current(): ?CurrentOrg;

    /** L'associazione corrente, o un'eccezione se manca. */
    public function require(): CurrentOrg;

    /**
     * Esegue $callback dentro l'associazione e ripristina lo stato precedente, anche in caso di errore.
     *
     * @template T
     *
     * @param  callable(CurrentOrg): T  $callback
     * @return T
     */
    public function run(string $orgId, callable $callback): mixed;

    /**
     * Esegue $callback con i tempi massimi delle operazioni lunghe (config saas.database_timeouts.long) al posto di quelli
     * delle richieste. Nelle rotte lunghe lo fa il middleware «db.long» (copie, importazioni, libro soci, esportazioni,
     * rendiconto, verifica delle ricevute, chiusura dell'anno, archivio); comandi e code li hanno già per tutto il processo.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLongTimeouts(callable $callback): mixed;
}
