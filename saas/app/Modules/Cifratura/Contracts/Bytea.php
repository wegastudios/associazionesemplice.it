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
            // Lo stream di PDO contiene già i byte: non si interpreta mai come testo esadecimale (un valore binario può
            // cominciare per caso con i byte «\x»). Lo stesso stream si rilegge ogni volta: si riparte dall'inizio.
            $raw = stream_get_contents($value, -1, 0);

            return is_string($raw) ? $raw : throw new UnexpectedValueException('Valore binario non valido.');
        }
        if (! is_string($value)) {
            throw new UnexpectedValueException('Valore binario non valido.');
        }
        if (str_starts_with($value, '\\x')) {
            $hex = substr($value, 2);
            $decoded = ($hex === '' || (strlen($hex) % 2 === 0 && ctype_xdigit($hex))) ? hex2bin($hex) : false;

            return $decoded === false ? throw new UnexpectedValueException('Valore binario non valido.') : $decoded;
        }

        return $value;
    }
}
