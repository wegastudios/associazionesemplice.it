<?php

declare(strict_types=1);

namespace KeyService;

/** Ricorda i nonce già usati, per rifiutare una richiesta ripetuta. */
interface NonceStore
{
    /** true se il nonce era già stato visto; altrimenti lo registra per $ttl secondi. */
    public function seen(string $nonce, int $ttl): bool;
}
