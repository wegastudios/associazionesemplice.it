<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Evento: è nata un'associazione. Si emette dentro la sua associazione (OrgContext) e nella transazione della
 * creazione: i moduli preparano qui i loro dati di partenza (conti, categorie, esercizio…).
 */
final readonly class OrgCreated
{
    public function __construct(public string $orgId) {}
}
