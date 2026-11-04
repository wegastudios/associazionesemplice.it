<?php

declare(strict_types=1);

namespace KeyService;

/** Nonce in memoria: per i test. */
final class MemoryNonceStore implements NonceStore
{
    /** @var array<string, true> */
    private array $seen = [];

    public function seen(string $nonce, int $ttl): bool
    {
        if (isset($this->seen[$nonce])) {
            return true;
        }
        $this->seen[$nonce] = true;

        return false;
    }
}
