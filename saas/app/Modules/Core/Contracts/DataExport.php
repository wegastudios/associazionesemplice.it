<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Una parte dell'esportazione completa dei dati dell'associazione, fornita dal modulo a cui appartengono. I moduli la
 * registrano con il tag DataExport::TAG; l'esportazione raccoglie i file di tutti in un archivio.
 */
interface DataExport
{
    public const TAG = 'saas.data-export';

    /**
     * I file di questo modulo, per quello che l'operatore può vedere.
     *
     * @return array<string, string> percorso nell'archivio => contenuto
     */
    public function files(Operator $operator): array;
}
