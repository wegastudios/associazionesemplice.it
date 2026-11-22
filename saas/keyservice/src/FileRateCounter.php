<?php

declare(strict_types=1);

namespace KeyService;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contatore su file, uno per nome e per minuto, in una cartella fuori dalla cartella pubblica. Incremento con blocco.
 * I file dei minuti passati si tolgono dopo 5 minuti (uno per associazione attiva nel minuto, più i totali).
 */
final class FileRateCounter implements RateCounter
{
    public function __construct(private readonly string $directory) {}

    public function hit(string $name, int $now): int
    {
        // Lettere, cifre e trattini: «unwrap», «batch», «org-<identificativo>». Mai punti né barre.
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException('Nome del contatore non valido.');
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Cartella dei contatori non disponibile.');
        }

        $handle = fopen($this->directory.'/'.$name.'-'.intdiv($now, 60), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Contatore non disponibile.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Contatore non disponibile.');
            }
            $raw = stream_get_contents($handle);
            $count = (is_string($raw) ? (int) $raw : 0) + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $count);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (random_int(1, 100) === 1) {
            $this->purge($now);
        }

        return $count;
    }

    private function purge(int $now): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (filemtime($file) < $now - 300) {
                @unlink($file);
            }
        }
    }
}
