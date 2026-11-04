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

    /** Azzera `app.org_id` su ogni connessione usata, anche se non era impostato. */
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
}
