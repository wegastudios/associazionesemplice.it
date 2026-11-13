<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use RuntimeException;

/** Cancellazione definitiva dei dati di un'associazione sospesa o chiusa (docs/CANCELLAZIONE_DATI.md). */
interface OrgPurger
{
    /**
     * Cancella i dati dell'associazione: righe, file, utenti che appartengono solo a lei e per ultima la chiave. Lascia la
     * «lapide» (nome, indirizzo e date). Si può ripetere: riprende da dove si era fermata.
     *
     * @return array{rows: int, users: int}
     *
     * @throws RuntimeException se l'associazione è attiva, non esiste o ha un database dedicato (non ancora gestito)
     */
    public function purge(string $orgId): array;
}
