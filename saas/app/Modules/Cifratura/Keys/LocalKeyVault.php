<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use App\Modules\Cifratura\Contracts\KeyVault;
use KeyService\KeyWrap;
use SensitiveParameter;

/**
 * Ripiego: chiave principale nel file d'ambiente del SaaS (SAAS_MASTER_KEY), fuori dalla cartella pubblica.
 * Protegge i dump del database, non chi entra nell'account del SaaS. In produzione va usato il servizio chiavi.
 */
final readonly class LocalKeyVault implements KeyVault
{
    private KeyWrap $wrap;

    public function __construct(#[SensitiveParameter] string $masterKey)
    {
        $this->wrap = new KeyWrap($masterKey);
    }

    public function wrap(string $orgId, int $version, #[SensitiveParameter] string $dataKey): string
    {
        return $this->wrap->wrap($orgId, $version, $dataKey);
    }

    public function unwrap(string $orgId, int $version, string $wrapped): string
    {
        return $this->wrap->unwrap($orgId, $version, $wrapped);
    }
}
