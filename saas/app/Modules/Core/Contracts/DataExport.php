<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Closure;

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
     * Il contenuto può essere una chiusura che lo produce: si valuta solo quando il file va nell'archivio, uno alla volta,
     * così le parti grosse (registro delle azioni) non stanno tutte in memoria insieme.
     *
     * @return array<string, string|Closure(): string> percorso nell'archivio => contenuto
     */
    public function files(Operator $operator): array;
}
