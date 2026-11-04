<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use SensitiveParameter;

/** Chi custodisce la chiave principale: il servizio chiavi separato o, come ripiego, il file d'ambiente. */
interface KeyVault
{
    public function wrap(string $orgId, int $version, #[SensitiveParameter] string $dataKey): string;

    public function unwrap(string $orgId, int $version, string $wrapped): string;
}
