<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use RuntimeException;
use SensitiveParameter;

/**
 * File con materiale delle chiavi su disco: solo fuori dalla cartella web e fuori dalla versione installata (che il
 * rilascio sostituisce), mai sovrascritti, con permessi 600 fin dalla creazione.
 */
final class SecretFiles
{
    /** Percorso assoluto controllato del file da creare. */
    public static function target(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Percorso non valido.');
        }
        $dir = realpath(dirname($path));
        if ($dir === false || ! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException('La cartella di destinazione non esiste o non è scrivibile.');
        }
        foreach ([public_path(), storage_path('app/public'), base_path()] as $forbidden) {
            $real = realpath($forbidden);
            if ($real !== false && ($dir === $real || str_starts_with($dir.DIRECTORY_SEPARATOR, $real.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Il file va fuori dalla cartella web e fuori dalla versione installata (per esempio nella cartella home).');
            }
        }

        $target = $dir.DIRECTORY_SEPARATOR.basename($path);
        if (file_exists($target)) {
            throw new RuntimeException('Il file esiste già: non si sovrascrive. Scegli un altro nome.');
        }

        return $target;
    }

    /** Crea il file (mai sovrascrive) con permessi 600 e restituisce i byte scritti. */
    public static function write(string $target, #[SensitiveParameter] string $data): int
    {
        $previous = umask(0077);
        try {
            $handle = @fopen($target, 'x');
            if ($handle === false) {
                throw new RuntimeException('Il file non si può creare (esiste già o la cartella non è scrivibile).');
            }
            $written = fwrite($handle, $data);
            fflush($handle);
            fclose($handle);
        } finally {
            umask($previous);
        }
        chmod($target, 0600);

        if ($written === false || $written !== strlen($data)) {
            @unlink($target);
            throw new RuntimeException('Scrittura del file non riuscita.');
        }

        return $written;
    }

    public static function read(string $path): string
    {
        $data = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return $data === false ? throw new RuntimeException('Il file non esiste o non è leggibile.') : $data;
    }

    /** Ricopre il file con byte casuali e lo cancella. Sui dischi moderni non è una garanzia: è il meglio che si può fare da qui. */
    public static function shred(string $path): void
    {
        $size = filesize($path);
        $handle = @fopen($path, 'r+');
        if ($handle !== false && $size !== false && $size > 0) {
            fwrite($handle, random_bytes($size));
            fflush($handle);
            fclose($handle);
        } elseif ($handle !== false) {
            fclose($handle);
        }
        if (! @unlink($path)) {
            throw new RuntimeException('Il file non si può cancellare.');
        }
    }
}
