<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Crypto;

use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Cifratura\Keys\KeyRing;

/** Usa sempre la prima versione della chiave: una rotazione non deve rompere la catena del registro. */
final readonly class HmacSealer implements Sealer
{
    public function __construct(private KeyRing $keys) {}

    public function seal(string $orgId, string $data): string
    {
        $this->keys->current($orgId);

        return hash_hmac('sha256', $data, $this->keys->version($orgId, 1)->sealKey(), true);
    }

    public function verify(string $orgId, string $data, string $seal): bool
    {
        return hash_equals($this->seal($orgId, $data), $seal);
    }
}
