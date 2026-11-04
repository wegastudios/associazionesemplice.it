<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

/** Cellulari: "+39 333 123 4567", "0039 3331234567" e "333-1234567" sono lo stesso numero. Dal plugin. */
final class Phone
{
    public const MIN_DIGITS = 8;

    public const MAX_DIGITS = 15;

    /** Solo cifre, senza prefisso italiano. */
    public static function key(string $raw): string
    {
        $d = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '39') && strlen($d) >= 11 && strlen($d) <= 13) {
            $d = substr($d, 2);
        }

        return $d;
    }

    public static function isValid(string $raw): bool
    {
        $n = strlen(self::key($raw));

        return $n >= self::MIN_DIGITS && $n <= self::MAX_DIGITS;
    }

    /** Numero per i link wa.me; senza prefisso si assume l'Italia. */
    public static function whatsapp(string $raw): string
    {
        if (! self::isValid($raw)) {
            return '';
        }
        $d = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with(trim($raw), '+')) {
            return $d;
        }
        if (str_starts_with($d, '00')) {
            return substr($d, 2);
        }

        return '39'.self::key($raw);
    }
}
