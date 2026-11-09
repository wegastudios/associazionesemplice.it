<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

use RuntimeException;

/**
 * Cambiando la risorsa di un'attività alcune date future finirebbero su un orario già occupato. $conflicts elenca
 * cosa si sovrappone; $movable è vero se si può risolvere spostando le prenotazioni in conflitto sulla risorsa che si libera.
 */
final class ResourceChangeConflict extends RuntimeException
{
    /** @param  list<string>  $conflicts */
    public function __construct(public readonly array $conflicts, public readonly bool $movable)
    {
        parent::__construct('Sulla nuova risorsa ci sono sovrapposizioni: '.implode('; ', $conflicts).'.');
    }
}
