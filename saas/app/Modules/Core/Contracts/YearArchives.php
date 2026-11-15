<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Archivio degli anni fiscali chiusi: i file di un anno (per ora le ricevute in PDF) raccolti in un solo file cifrato,
 * al posto dei file singoli. Solo file, mai righe di dati. Gesto dell'amministratore; si può tornare indietro.
 */
interface YearArchives
{
    /**
     * Gli anni fiscali chiusi, dal più recente.
     *
     * @return list<array{year: int, files: int, state: 'ready'|'archived'|'not_yet', archived_on: string|null}>
     */
    public function years(): array;

    /**
     * Archivia l'anno: raccoglie, controlla e solo dopo toglie i file singoli. Ritorna quanti file ha archiviato.
     *
     * @throws ArchiveError
     */
    public function archive(int $year): int;

    /**
     * Rimette i file singoli dell'anno e toglie l'archivio. Ritorna quanti file ha rimesso.
     *
     * @throws ArchiveError
     */
    public function restore(int $year): int;
}
