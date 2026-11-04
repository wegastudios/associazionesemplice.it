<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use LogicException;

/** Si è provato a leggere o scrivere dati di un'associazione senza averla impostata. */
final class NoOrgContext extends LogicException
{
    public static function make(): self
    {
        return new self("Nessuna associazione impostata: i dati di un'associazione si usano solo dentro OrgContext.");
    }
}
