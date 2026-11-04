<?php

declare(strict_types=1);

namespace App\Modules\Soci\Rules;

/** Codice fiscale delle persone fisiche: forma e carattere di controllo, anche con l'omocodia. Dal plugin. */
final class TaxCode
{
    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    public static function isValid(string $value): bool
    {
        $c = self::normalize($value);
        if (preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $c) !== 1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $ch = $c[$i];
            $sum += $i % 2 === 0 ? self::ODD[$ch] : (ctype_digit($ch) ? (int) $ch : ord($ch) - 65);
        }

        return chr(65 + $sum % 26) === $c[15];
    }
}
