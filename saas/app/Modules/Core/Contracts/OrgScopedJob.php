<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Lavoro in coda che riguarda una sola associazione. Il payload contiene solo identificativi, mai dati
 * personali: la coda sta nel database centrale anche per le associazioni con database dedicato.
 */
interface OrgScopedJob
{
    public function orgId(): string;
}
