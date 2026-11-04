<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

/** Chiavi dati delle associazioni (core.org_keys), cifrate con la chiave principale. */
interface OrgKeys
{
    /** Crea la prima chiave dell'associazione, se non c'è ancora. */
    public function create(string $orgId): void;
}
