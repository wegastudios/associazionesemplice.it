<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

/**
 * Spazio per i file dell'associazione, secondo il piano (`storage_gb` dei diritti). Si chiama prima di salvare un file
 * caricato da una persona; i documenti che il gestionale produce da sé (ricevute, archivi) non passano di qui.
 */
interface StorageQuota
{
    /**
     * Fa passare il file solo se, con $incomingBytes in più, lo spazio resta nel limite del piano.
     * Senza limite nei diritti (zero o assente) non blocca nulla.
     *
     * @throws StorageFull
     */
    public function assertRoom(string $orgId, int $incomingBytes): void;
}
