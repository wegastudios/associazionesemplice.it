<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

/**
 * Sigillo (HMAC-SHA256) con una chiave dell'associazione. Chi altera il registro delle azioni senza la chiave, che
 * sta dietro il servizio chiavi, non riesce a ricalcolare i sigilli.
 */
interface Sealer
{
    public function seal(string $orgId, string $data): string;

    public function verify(string $orgId, string $data, string $seal): bool;
}
