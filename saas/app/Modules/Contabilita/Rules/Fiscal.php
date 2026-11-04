<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Rules;

use App\Modules\Soci\Contracts\PersonTaxCode;

/** Partita IVA e codice fiscale dell'ente (dal Fiscal del plugin). */
final class Fiscal
{
    public static function normalizeVat(string $v): string
    {
        $v = strtoupper((string) preg_replace('/[\s.\-]+/', '', $v));

        return str_starts_with($v, 'IT') ? substr($v, 2) : $v;
    }

    public static function isValidVat(string $v): bool
    {
        $v = self::normalizeVat($v);
        if (preg_match('/^\d{11}$/', $v) !== 1 || $v === '00000000000') {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $d = (int) $v[$i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (10 - $sum % 10) % 10 === (int) $v[10];
    }

    /** Codice fiscale di un ente: 11 cifre con cifra di controllo, oppure il codice a 16 caratteri di una persona fisica. */
    public static function isValidEntityTaxCode(string $s): bool
    {
        $s = PersonTaxCode::normalize($s);
        if (preg_match('/^\d{11}$/', $s) === 1) {
            return self::isValidVat($s);
        }

        return strlen($s) === 16 && PersonTaxCode::isValid($s);
    }
}
