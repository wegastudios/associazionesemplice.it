<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File allegati delle associazioni: cifrati con la chiave dell'associazione, fuori dal database e dalla cartella
 * pubblica. Si scaricano solo da download(), dopo il controllo dei permessi di chi chiama.
 */
interface EncryptedFiles
{
    /** Cifra e salva il file in $sourcePath (per esempio un upload temporaneo). */
    public function store(string $orgId, string $sourcePath, string $originalName, string $mime): StoredFile;

    public function read(string $orgId, StoredFile $file): string;

    /** Decifra il file in $targetPath, a blocchi (senza caricarlo in memoria), controllando l'impronta. */
    public function readToFile(string $orgId, StoredFile $file, string $targetPath): void;

    public function download(string $orgId, StoredFile $file): StreamedResponse;

    public function delete(string $orgId, StoredFile $file): void;

    /**
     * Spazio occupato sul disco dai file cifrati dell'associazione, in byte, compresa la copia di sicurezza completa
     * finché resta scaricabile. Viene da un contatore: senza contatore si riconta dal disco.
     */
    public function usedBytes(string $orgId): int;

    /** Riconta lo spazio dal disco e riallinea il contatore (ogni notte, saas:files:usage). */
    public function recountUsedBytes(string $orgId): int;
}
