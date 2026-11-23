<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Evento: sono cambiati lo stato dell'associazione (attiva, sospesa, chiusa) o i suoi diritti. Chi tiene una copia
 * tra le richieste (i diritti, H20) la dimentica subito: una sospensione deve valere dalla richiesta successiva.
 */
final readonly class OrgAccessChanged
{
    public function __construct(public string $orgId) {}
}
