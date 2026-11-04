<?php

declare(strict_types=1);

namespace KeyService;

use InvalidArgumentException;

/** Nonce su file, in una cartella fuori dalla cartella pubblica. Crea il file in modo atomico. */
final class FileNonceStore implements NonceStore
{
    public function __construct(private readonly string $directory) {}

    public function seen(string $nonce, int $ttl): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            throw new InvalidArgumentException('Nonce non valido.');
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new InvalidArgumentException('Cartella dei nonce non disponibile.');
        }

        if (random_int(1, 100) === 1) {
            $this->purge($ttl);
        }

        $handle = @fopen($this->directory.'/'.$nonce, 'x');
        if ($handle === false) {
            return true;
        }
        fclose($handle);

        return false;
    }

    private function purge(int $ttl): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (filemtime($file) < time() - $ttl) {
                @unlink($file);
            }
        }
    }
}
