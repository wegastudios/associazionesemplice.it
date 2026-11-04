<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

/** IBAN (ISO 13616, controllo mod 97). Dal plugin. */
final class Iban
{
    private const LENGTHS = [
        'IT' => 27, 'SM' => 27, 'VA' => 22, 'CH' => 21, 'LI' => 21, 'FR' => 27, 'MC' => 27, 'DE' => 22, 'ES' => 24, 'PT' => 25, 'GB' => 22, 'IE' => 22,
        'NL' => 18, 'BE' => 16, 'LU' => 20, 'AT' => 20, 'MT' => 31, 'GR' => 27, 'CY' => 28, 'SI' => 19, 'HR' => 21, 'SK' => 24, 'PL' => 28,
    ];

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/', '', $value));
    }

    public static function isValid(string $value): bool
    {
        $n = self::normalize($value);
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $n) !== 1) {
            return false;
        }
        $country = substr($n, 0, 2);
        if (isset(self::LENGTHS[$country]) && strlen($n) !== self::LENGTHS[$country]) {
            return false;
        }

        return self::mod97(substr($n, 4).substr($n, 0, 4)) === 1;
    }

    public static function format(string $value): string
    {
        return trim(implode(' ', str_split(self::normalize($value), 4)));
    }

    /** Per i registri: «IT** **** 3456». */
    public static function mask(string $value): string
    {
        $n = self::normalize($value);

        return strlen($n) < 8 ? '' : substr($n, 0, 2).'** **** '.substr($n, -4);
    }

    private static function mod97(string $value): int
    {
        $digits = '';
        foreach (str_split($value) as $ch) {
            $digits .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $rem = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rem = (int) ($rem.$chunk) % 97;
        }

        return $rem;
    }
}
