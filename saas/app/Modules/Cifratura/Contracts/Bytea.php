<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

use UnexpectedValueException;

/**
 * Valori binari nelle colonne bytea. PDO li manderebbe come testo e si romperebbero sui byte nulli: si scrivono nel
 * formato esadecimale di PostgreSQL (\x…) e si leggono dallo stream che PDO restituisce.
 */
final class Bytea
{
    public static function encode(string $binary): string
    {
        return '\\x'.bin2hex($binary);
    }

    public static function decode(mixed $value): string
    {
        if (is_resource($value)) {
            // Lo stesso stream si rilegge ogni volta che si accede al campo: si riparte dall'inizio.
            $value = stream_get_contents($value, -1, 0);
        }
        if (! is_string($value)) {
            throw new UnexpectedValueException('Valore binario non valido.');
        }
        if (str_starts_with($value, '\\x')) {
            $decoded = hex2bin(substr($value, 2));

            return $decoded === false ? throw new UnexpectedValueException('Valore binario non valido.') : $decoded;
        }

        return $value;
    }
}
