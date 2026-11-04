<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** Elenco dei database delle associazioni (condiviso e dedicati), per i lavori di manutenzione su tutti. */
interface DatabaseNames
{
    /** @return list<string> */
    public function names(): array;

    /** Connessione del proprietario: solo manutenzione dello schema (migrazioni, partizioni). */
    public function ownerConnection(?string $name): string;

    /** Connessione del ruolo dei backup: sola lettura, ammessa dalle policy. */
    public function backupConnection(?string $name): string;
}
